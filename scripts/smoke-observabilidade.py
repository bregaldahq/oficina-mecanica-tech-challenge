#!/usr/bin/env python3
"""
Smoke test de observabilidade: falha o deploy se a telemetria não estiver chegando.

Por que isto existe
-------------------
Este projeto acumulou seis falhas do mesmo tipo, todas com o pipeline verde:

- a license key configurada não era a license key da conta;
- trocar o Secret não reiniciava os pods, então o agente seguia com o valor antigo;
- o PHP-FPM descartava a saída dos workers e nenhum log saía do pod;
- os dashboards filtravam por um valor de `env` que nenhum evento carregava.

Nenhuma dessas quebrou um deploy. Todas foram descobertas dias depois, olhando um
gráfico vazio. Observabilidade tem essa característica ruim: quando ela falha, o
sintoma é ausência — e ausência não dispara alarme.

Este script converte ausência em falha de pipeline.

O que verifica
--------------
1. `Transaction`     — o agente APM da aplicação está reportando
2. `Log`             — os logs do container da aplicação estão sendo coletados
3. `K8sPodSample`    — o nri-bundle está reportando o cluster

Cada falha vem com a causa provável, para não repetir a investigação do zero.

Uso
---
    export NEW_RELIC_API_KEY=NRAK-...
    export NEW_RELIC_ACCOUNT_ID=8475782

    scripts/smoke-observabilidade.py --env hml
    scripts/smoke-observabilidade.py --env hml --janela 20 --timeout 300
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

NERDGRAPH = {
    "us": "https://api.newrelic.com/graphql",
    "eu": "https://api.eu.newrelic.com/graphql",
}


def nrql(consulta: str, conta: int, chave: str, regiao: str) -> list[dict]:
    corpo = {
        "query": "query($c: Int!, $q: Nrql!) { actor { account(id: $c) "
                 "{ nrql(query: $q) { results } } } }",
        "variables": {"c": conta, "q": consulta},
    }
    req = urllib.request.Request(
        NERDGRAPH[regiao],
        data=json.dumps(corpo).encode(),
        headers={"Content-Type": "application/json", "API-Key": chave},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=45) as r:
            dados = json.loads(r.read())
    except urllib.error.HTTPError as e:
        raise SystemExit(f"NerdGraph HTTP {e.code}: {e.read().decode()[:300]}")

    if dados.get("errors"):
        raise SystemExit("NerdGraph recusou:\n" + json.dumps(dados["errors"], indent=2))

    return (((dados.get("data") or {}).get("actor") or {}).get("account") or {}) \
        .get("nrql", {}).get("results") or []


def contagem(consulta: str, conta: int, chave: str, regiao: str) -> int:
    r = nrql(consulta, conta, chave, regiao)
    if not r:
        return 0
    primeiro = r[0]
    for campo in ("count", "count(*)", "uniqueCount.podName"):
        if campo in primeiro:
            return int(primeiro[campo] or 0)
    # qualquer valor numerico serve
    for v in primeiro.values():
        if isinstance(v, (int, float)):
            return int(v)
    return 0


def main() -> None:
    ap = argparse.ArgumentParser(description="Falha se a telemetria nao estiver chegando.")
    ap.add_argument("--env", required=True, choices=["hml", "prod"])
    ap.add_argument("--janela", type=int, default=15,
                    help="minutos de janela consultada (default 15)")
    ap.add_argument("--timeout", type=int, default=300,
                    help="segundos aguardando a telemetria aparecer (default 300)")
    ap.add_argument("--cluster", default="",
                    help="nome do cluster no New Relic (default oficina-<env>-eks). "
                         "Um apply feito com o ambiente errado deixa o nri-bundle "
                         "reportando outro nome — por isso e' parametro, nao suposicao.")
    ap.add_argument("--region", default="us", choices=["us", "eu"])
    ap.add_argument("--permitir-falha", action="store_true",
                    help="reporta mas sai com 0 — para adocao gradual")
    args = ap.parse_args()

    chave = os.environ.get("NEW_RELIC_API_KEY", "")
    conta = os.environ.get("NEW_RELIC_ACCOUNT_ID", "")

    if not chave or not conta.isdigit():
        print("::notice::NEW_RELIC_API_KEY ou NEW_RELIC_ACCOUNT_ID ausente — smoke de "
              "observabilidade pulado.")
        return

    conta_i = int(conta)
    app = f"oficina-api-{args.env}"
    cluster = args.cluster or f"oficina-{args.env}-eks"
    j = args.janela

    checagens = [
        (
            "APM da aplicacao",
            f"SELECT count(*) FROM Transaction WHERE appName = '{app}' SINCE {j} minutes ago",
            "O agente PHP nao esta reportando. Causas, em ordem:\n"
            "  1. NEW_RELIC_LICENSE_KEY nao e' a license key da conta (uma User key NAO serve).\n"
            "  2. O Secret mudou mas os pods nao reiniciaram — variavel de ambiente e' fixada\n"
            "     no start do pod. Confira a anotacao oficina/newrelic-key-hash no Deployment.\n"
            "  3. A extensao nao carregou na imagem: docker run <img> php -m | grep newrelic",
        ),
        (
            "Logs da aplicacao",
            f"SELECT count(*) FROM Log WHERE container_name = 'php-fpm' SINCE {j} minutes ago",
            "Os logs do container da aplicacao nao chegam. Causas, em ordem:\n"
            "  1. catch_workers_output desligado: o PHP-FPM descarta a saida dos workers,\n"
            "     entao o JsonLogger escreve e nada sai do pod. Ver docker/php/zz-logging.conf.\n"
            "  2. O newrelic-logging (Fluent Bit) nao esta rodando no cluster.",
        ),
        (
            "Metricas do cluster",
            f"SELECT uniqueCount(podName) FROM K8sPodSample WHERE clusterName = '{cluster}' "
            f"SINCE {j} minutes ago",
            "O nri-bundle nao esta reportando. Causas, em ordem:\n"
            "  1. A release Helm nao foi instalada — o repo de k8s a pula quando\n"
            "     NEW_RELIC_LICENSE_KEY esta vazio no momento do apply.\n"
            "  2. O nome do cluster mudou e nao bate com o esperado.",
        ),
    ]

    print(f"==> conta {conta_i} · ambiente {args.env} · janela de {j} min")
    print(f"    appName={app}  clusterName={cluster}\n")

    limite = time.time() + args.timeout
    pendentes = list(checagens)
    resultados: dict[str, int] = {}
    tentativa = 0

    # A telemetria tem atraso de 1 a 3 minutos. Insistir evita falso negativo logo
    # apos o deploy — que seria pior que nao ter teste, porque ensina a ignora-lo.
    while pendentes and time.time() < limite:
        tentativa += 1
        ainda: list = []
        for nome, consulta, _ in pendentes:
            n = contagem(consulta, conta_i, chave, args.region)
            resultados[nome] = n
            if n > 0:
                print(f"  ok       {nome}: {n}")
            else:
                ainda.append((nome, consulta, _))
        pendentes = ainda
        if pendentes and time.time() < limite:
            restante = int(limite - time.time())
            print(f"  ...      aguardando {len(pendentes)} sinal(is), {restante}s restantes")
            time.sleep(20)

    print()
    if not pendentes:
        print("==> Telemetria confirmada nos tres sinais.")
        return

    print("=" * 62)
    for nome, _, dica in pendentes:
        print(f"FALHOU: {nome} — nenhum dado em {j} minutos")
        print(dica)
        print()
    print("=" * 62)

    if args.permitir_falha:
        print("::warning::Smoke de observabilidade falhou, mas --permitir-falha esta ativo.")
        return

    sys.exit(1)


if __name__ == "__main__":
    main()
