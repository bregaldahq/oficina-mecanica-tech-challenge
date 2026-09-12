#!/usr/bin/env python3
"""
Popula os dashboards de negócio do New Relic movimentando ordens de serviço pela API.

Por que isto existe
-------------------
Os 15 painéis do dashboard de negócio consultam `ServiceOrderCreated` e
`ServiceOrderStatusChanged` — custom events do New Relic, emitidos pela APLICAÇÃO
quando um evento de domínio acontece.

O seed (`003_seed_demo.sql`) inseriu 120 ordens direto no MySQL. SQL não dispara
evento de domínio, então aquelas ordens não produziram um único custom event: o
banco fica cheio e os painéis, vazios.

A única forma de alimentar esses painéis é movimentar ordens pela API de verdade.

Duração realista
----------------
`durationSeconds` é calculado como *agora − última transição registrada no histórico*.

- Avançar uma ordem **do seed**, cuja última transição é de dias atrás, produz
  duração de horas ou dias — que é como um gráfico de "tempo médio por status"
  deve parecer.
- Criar uma ordem agora e avançá-la em seguida produz duração de segundos.

Por isso o script faz as duas coisas: avança ordens antigas (para o tempo por
status ficar crível) e cria ordens novas (para o volume diário ter barra hoje).

Uso
---
    export ADMIN_PASSWORD='...'        # Secrets Manager: oficina/hml/auth
    export WEBHOOK_TOKEN='...'         # opcional, só para gerar REJECTED

    scripts/popular-dashboards.py --dry-run
    scripts/popular-dashboards.py
    scripts/popular-dashboards.py --novas 25 --avancar 40
"""

from __future__ import annotations

import argparse
import json
import os
import random
import sys
import time
import urllib.error
import urllib.request

API_PADRAO = "https://lkdvezfrm5.execute-api.us-east-1.amazonaws.com"

# Caminho feliz da máquina de estados (ServiceOrder.php).
# REJECTED sai de AWAITING_APPROVAL, mas só pelo webhook de aprovação.
FLUXO = ["DIAGNOSIS", "AWAITING_APPROVAL", "EXECUTING", "FINISHED", "DELIVERED"]
PROXIMO = {
    "RECEIVED": "DIAGNOSIS",
    "DIAGNOSIS": "AWAITING_APPROVAL",
    "AWAITING_APPROVAL": "EXECUTING",
    "EXECUTING": "FINISHED",
    "FINISHED": "DELIVERED",
}


def http(metodo: str, url: str, token: str | None = None, corpo: dict | None = None,
         extra: dict | None = None) -> tuple[int, object]:
    dados = json.dumps(corpo).encode() if corpo is not None else None
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    if extra:
        headers.update(extra)

    req = urllib.request.Request(url, data=dados, headers=headers, method=metodo)
    try:
        with urllib.request.urlopen(req, timeout=45) as r:
            texto = r.read().decode()
            return r.status, (json.loads(texto) if texto.strip() else None)
    except urllib.error.HTTPError as e:
        texto = e.read().decode()
        try:
            return e.code, json.loads(texto)
        except json.JSONDecodeError:
            return e.code, texto[:200]
    except urllib.error.URLError as e:
        return 0, str(e.reason)


def entrar(api: str, senha: str) -> str:
    st, corpo = http("POST", f"{api}/api/auth/login",
                     corpo={"username": os.environ.get("ADMIN_USERNAME", "admin"), "password": senha})
    if st != 200 or not isinstance(corpo, dict) or "token" not in corpo:
        sys.exit(f"Login falhou (HTTP {st}): {corpo}\n"
                 "A senha está em: aws secretsmanager get-secret-value "
                 "--secret-id oficina/hml/auth --region us-east-1")
    return corpo["token"]


def avancar(api: str, token: str, order_id: str, status: str) -> bool:
    st, _ = http("PATCH", f"{api}/api/service-orders/{order_id}/status",
                 token=token, corpo={"status": status})
    return st == 200


def rejeitar(api: str, order_id: str, webhook: str) -> bool:
    st, _ = http("POST", f"{api}/api/service-orders/{order_id}/approval",
                 corpo={"approved": False},
                 extra={"X-Webhook-Token": webhook})
    return st == 200


def main() -> None:
    ap = argparse.ArgumentParser(description="Movimenta ordens para popular os dashboards.")
    ap.add_argument("--api", default=os.environ.get("API", API_PADRAO))
    ap.add_argument("--novas", type=int, default=18, help="ordens novas a criar")
    ap.add_argument("--avancar", type=int, default=35, help="ordens do seed a movimentar")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    senha = os.environ.get("ADMIN_PASSWORD", "")
    webhook = os.environ.get("WEBHOOK_TOKEN", "")
    if not senha:
        sys.exit("ADMIN_PASSWORD não definido. Pegue em oficina/hml/auth no Secrets Manager.")

    print(f"==> {args.api}")
    token = entrar(args.api, senha)
    print("    autenticado como admin")

    st, clientes = http("GET", f"{args.api}/api/customers", token=token)
    st2, veiculos = http("GET", f"{args.api}/api/vehicles", token=token)
    if st != 200 or st2 != 200:
        sys.exit(f"Falha ao listar base (clientes={st}, veiculos={st2})")

    # vehicle -> customer, para criar OS com o par coerente
    pares = [(v["customer_id"], v["id"]) for v in veiculos if v.get("customer_id")]
    print(f"    {len(clientes)} clientes, {len(veiculos)} veículos, {len(pares)} pares utilizáveis")

    st, ativas = http("GET", f"{args.api}/api/service-orders", token=token)
    if st != 200:
        sys.exit(f"Falha ao listar ordens: {ativas}")
    print(f"    {len(ativas)} ordens ativas (o endpoint exclui FINISHED e DELIVERED)")

    if args.dry_run:
        dist: dict[str, int] = {}
        for o in ativas:
            dist[o["status"]] = dist.get(o["status"], 0) + 1
        print("\n[dry-run] distribuição atual das ativas:")
        for s, n in sorted(dist.items()):
            print(f"    {s:<20} {n}")
        print(f"\n[dry-run] criaria {args.novas} ordens e movimentaria até {args.avancar} do seed.")
        print("[dry-run] nada foi alterado.")
        return

    random.seed()

    # ── 1. Avança ordens já existentes ──────────────────────────────────────
    # Estas têm histórico antigo, então o durationSeconds sai em horas/dias.
    print("\nMovimentando ordens existentes (duração realista):")
    alvo = [o for o in ativas if o["status"] in PROXIMO][: args.avancar]
    movidas = rejeitadas = 0

    for o in alvo:
        atual = o["status"]
        # ~15% das que estão aguardando aprovação viram REJECTED, se houver webhook
        if atual == "AWAITING_APPROVAL" and webhook and random.random() < 0.15:
            if rejeitar(args.api, o["id"], webhook):
                rejeitadas += 1
                print(f"    {o['id'][:8]}  {atual:<18} -> REJECTED")
                continue

        # avança 1 ou 2 passos, para espalhar a distribuição final
        passos = random.choice([1, 1, 2])
        for _ in range(passos):
            prox = PROXIMO.get(atual)
            if not prox:
                break
            if avancar(args.api, token, o["id"], prox):
                print(f"    {o['id'][:8]}  {atual:<18} -> {prox}")
                atual = prox
                movidas += 1
                time.sleep(0.4)
            else:
                break

    # ── 2. Cria ordens novas ────────────────────────────────────────────────
    # Alimentam o "volume diário de OS" com barra no dia de hoje.
    print(f"\nCriando {args.novas} ordens novas:")
    criadas = 0
    novas_ids: list[str] = []

    for _ in range(args.novas):
        cid, vid = random.choice(pares)
        st, corpo = http("POST", f"{args.api}/api/service-orders", token=token,
                         corpo={"customer_id": cid, "vehicle_id": vid})
        if st in (200, 201) and isinstance(corpo, dict) and corpo.get("id"):
            criadas += 1
            novas_ids.append(corpo["id"])
        time.sleep(0.3)
    print(f"    {criadas} criadas")

    # Leva parte delas adiante, com pausa entre transições para a duração não ser zero.
    print("\nEspalhando as novas pelos status:")
    for i, oid in enumerate(novas_ids):
        # deixa ~25% em RECEIVED, o resto avança de 1 a 4 passos
        passos = [0, 1, 1, 2, 2, 3, 4][i % 7]
        atual = "RECEIVED"
        for _ in range(passos):
            prox = PROXIMO.get(atual)
            if not prox or not avancar(args.api, token, oid, prox):
                break
            atual = prox
            time.sleep(1.2)
        if passos:
            print(f"    {oid[:8]}  RECEIVED -> {atual}")

    # ── 3. Relatório ────────────────────────────────────────────────────────
    st, ativas_depois = http("GET", f"{args.api}/api/service-orders", token=token)
    dist: dict[str, int] = {}
    if st == 200:
        for o in ativas_depois:
            dist[o["status"]] = dist.get(o["status"], 0) + 1

    print("\n" + "=" * 52)
    print(f"  transições aplicadas : {movidas}")
    print(f"  ordens rejeitadas    : {rejeitadas}")
    print(f"  ordens criadas       : {criadas}")
    print("\n  distribuição das ATIVAS agora:")
    for s, n in sorted(dist.items()):
        print(f"    {s:<20} {n}")
    print("\n  FINISHED e DELIVERED não aparecem acima: o endpoint de listagem")
    print("  exclui as concluídas de propósito.")
    print("=" * 52)
    print("\nOs custom events levam de 1 a 3 minutos para aparecer no New Relic.")
    print("Confira em: https://one.newrelic.com/dashboards")


if __name__ == "__main__":
    main()
