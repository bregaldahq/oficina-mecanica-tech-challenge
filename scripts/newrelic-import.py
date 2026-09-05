#!/usr/bin/env python3
"""
Importa dashboards, política de alertas e monitor Synthetic no New Relic.

Os arquivos em docs/fase-3/newrelic/ são versionados com o ambiente `prod` fixo nas
consultas NRQL e com `accountIds: [0]` como placeholder. Importar assim num ambiente
que não é `prod` produz painéis estruturalmente corretos e **completamente vazios**,
sem nenhum erro — o modo de falha mais caro que existe em observabilidade.

Este script resolve as duas coisas: injeta o account ID real e reescreve o ambiente
alvo em todas as consultas antes de enviar.

Uso:
    export NEW_RELIC_API_KEY=NRAK-...        # User key, não a license key
    export NEW_RELIC_ACCOUNT_ID=1234567

    scripts/newrelic-import.py --env hml --endpoint https://xxxx.execute-api.us-east-1.amazonaws.com
    scripts/newrelic-import.py --env hml --endpoint https://... --dry-run

Ao final imprime os entity GUIDs criados — são eles que alimentam os secrets
NEW_RELIC_INFRA_ENTITY_GUID e NEW_RELIC_LAMBDA_ENTITY_GUID.
"""

from __future__ import annotations

import argparse
import json
import os
import pathlib
import re
import sys
import urllib.error
import urllib.request

NERDGRAPH = "https://api.newrelic.com/graphql"
BASE = pathlib.Path(__file__).resolve().parent.parent / "docs" / "fase-3" / "newrelic"


# --------------------------------------------------------------------------- HTTP


def nerdgraph(query: str, variables: dict, api_key: str) -> dict:
    payload = json.dumps({"query": query, "variables": variables}).encode()
    req = urllib.request.Request(
        NERDGRAPH,
        data=payload,
        headers={"Content-Type": "application/json", "API-Key": api_key},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            body = json.loads(resp.read())
    except urllib.error.HTTPError as e:
        raise SystemExit(f"HTTP {e.code} do NerdGraph: {e.read().decode()[:400]}")

    if body.get("errors"):
        raise SystemExit("NerdGraph recusou:\n" + json.dumps(body["errors"], indent=2, ensure_ascii=False))
    return body["data"]


# ------------------------------------------------------------------- substituição


def retarget(obj, env: str, account_id: int, endpoint: str):
    """Reescreve ambiente e account id recursivamente."""
    if isinstance(obj, dict):
        return {k: retarget(v, env, account_id, endpoint) for k, v in obj.items()}
    if isinstance(obj, list):
        # accountIds: [0] -> [<real>]
        if obj == [0]:
            return [account_id]
        return [retarget(v, env, account_id, endpoint) for v in obj]
    if isinstance(obj, str):
        s = obj
        # appName = 'oficina-api-prod'  ->  '...-<env>'
        s = s.replace("oficina-api-prod", f"oficina-api-{env}")
        # recursos AWS: oficina-prod-api, oficina-prod-auth-cpf, ...
        s = re.sub(r"oficina-prod-", f"oficina-{env}-", s)
        # atributo dos custom events e dos logs
        s = re.sub(r"env\s*=\s*'prod'", f"env = '{env}'", s)
        # clusterName / nomes soltos
        s = s.replace("oficina-prod", f"oficina-{env}")
        # nome da política e títulos: "Oficina Mecânica · prod"
        s = s.replace("· prod", f"· {env}")
        s = s.replace("<apigw-endpoint>", endpoint.replace("https://", "").rstrip("/"))
        s = s.replace("ambiente de produção", f"ambiente {env}")
        return s
    return obj


def load(name: str, env: str, account_id: int, endpoint: str):
    raw = json.loads((BASE / name).read_text(encoding="utf-8"))
    return retarget(raw, env, account_id, endpoint)


# ---------------------------------------------------------------------- mutations

Q_DASHBOARD = """
mutation($accountId: Int!, $dashboard: DashboardInput!) {
  dashboardCreate(accountId: $accountId, dashboard: $dashboard) {
    entityResult { guid name }
    errors { description type }
  }
}
"""

Q_POLICY = """
mutation($accountId: Int!, $policy: AlertsPolicyInput!) {
  alertsPolicyCreate(accountId: $accountId, policy: $policy) { id name }
}
"""

Q_CONDITION = """
mutation($accountId: Int!, $policyId: ID!, $condition: AlertsNrqlConditionStaticInput!) {
  alertsNrqlConditionStaticCreate(accountId: $accountId, policyId: $policyId, condition: $condition) {
    id name
  }
}
"""

Q_SYNTHETIC = """
mutation($accountId: Int!, $monitor: SyntheticsCreateSimpleMonitorInput!) {
  syntheticsCreateSimpleMonitor(accountId: $accountId, monitor: $monitor) {
    monitor { guid name }
    errors { description type }
  }
}
"""


def create_dashboard(path: str, env, acct, endpoint, key, dry) -> str | None:
    dash = load(path, env, acct, endpoint)
    dash["name"] = f"{dash['name']} · {env}"
    dash.pop("permissions", None) or dash.setdefault("permissions", "PUBLIC_READ_WRITE")
    dash["permissions"] = "PUBLIC_READ_WRITE"

    if dry:
        pages = ", ".join(f"{p['name']} ({len(p.get('widgets', []))} painéis)" for p in dash["pages"])
        print(f"  [dry-run] dashboardCreate  {dash['name']}  ->  {pages}")
        return None

    data = nerdgraph(Q_DASHBOARD, {"accountId": acct, "dashboard": dash}, key)
    res = data["dashboardCreate"]
    if res.get("errors"):
        raise SystemExit(f"Falha ao criar o dashboard {dash['name']}:\n{json.dumps(res['errors'], indent=2)}")
    guid = res["entityResult"]["guid"]
    print(f"  criado  {res['entityResult']['name']}  guid={guid}")
    return guid


def create_alerts(env, acct, endpoint, key, dry) -> None:
    doc = load("alertas.json", env, acct, endpoint)

    policy_in = {
        "name": doc["policy"]["name"],
        "incidentPreference": doc["policy"]["incidentPreference"],
    }

    if dry:
        print(f"  [dry-run] alertsPolicyCreate  {policy_in['name']}")
        for c in doc["conditions"]:
            print(f"  [dry-run]   condição  {c['name']}")
        return

    policy = nerdgraph(Q_POLICY, {"accountId": acct, "policy": policy_in}, key)["alertsPolicyCreate"]
    print(f"  política criada  {policy['name']}  id={policy['id']}")

    for c in doc["conditions"]:
        cond = {
            "name": c["name"],
            "enabled": c.get("enabled", True),
            "nrql": c["nrql"],
            "signal": c["signal"],
            "terms": c["terms"],
            "violationTimeLimitSeconds": c.get("violationTimeLimitSeconds", 86400),
        }
        if c.get("description"):
            cond["description"] = c["description"]

        nerdgraph(Q_CONDITION, {"accountId": acct, "policyId": policy["id"], "condition": cond}, key)
        print(f"    condição  {c['name']}")


def create_synthetic(env, acct, endpoint, key, dry) -> None:
    doc = load("alertas.json", env, acct, endpoint)
    m = doc.get("syntheticMonitor")
    if not m:
        return

    monitor = {
        "name": m["name"],
        "period": m["period"],
        "status": m["status"],
        "uri": m["uri"] if m["uri"].startswith("http") else f"https://{m['uri']}",
        "locations": {"public": m["locations"]},
    }

    if dry:
        print(f"  [dry-run] syntheticsCreateSimpleMonitor  {monitor['name']}  {monitor['uri']}")
        return

    data = nerdgraph(Q_SYNTHETIC, {"accountId": acct, "monitor": monitor}, key)
    res = data["syntheticsCreateSimpleMonitor"]
    if res.get("errors"):
        raise SystemExit(f"Falha ao criar o monitor:\n{json.dumps(res['errors'], indent=2)}")
    print(f"  monitor criado  {res['monitor']['name']}  guid={res['monitor']['guid']}")


# -------------------------------------------------------------------------- main


def main() -> None:
    ap = argparse.ArgumentParser(description="Importa a observabilidade no New Relic.")
    ap.add_argument("--env", required=True, choices=["hml", "prod"])
    ap.add_argument("--endpoint", required=True, help="URL do API Gateway do ambiente")
    ap.add_argument("--dry-run", action="store_true", help="mostra o que faria, sem criar nada")
    ap.add_argument("--skip-synthetic", action="store_true")
    args = ap.parse_args()

    key = os.environ.get("NEW_RELIC_API_KEY", "")
    acct = os.environ.get("NEW_RELIC_ACCOUNT_ID", "")

    if not args.dry_run and not key.startswith("NRAK-"):
        sys.exit("NEW_RELIC_API_KEY ausente ou não é uma User key (deve começar com NRAK-).")
    if not acct.isdigit():
        sys.exit("NEW_RELIC_ACCOUNT_ID ausente ou não numérico.")

    acct_i = int(acct)
    print(f"==> conta {acct_i} · ambiente {args.env} · {args.endpoint}")
    if args.dry_run:
        print("==> DRY-RUN: nada será criado.\n")

    print("Dashboards:")
    create_dashboard("dashboard-negocio.json", args.env, acct_i, args.endpoint, key, args.dry_run)
    create_dashboard("dashboard-plataforma.json", args.env, acct_i, args.endpoint, key, args.dry_run)

    print("\nAlertas:")
    create_alerts(args.env, acct_i, args.endpoint, key, args.dry_run)

    if not args.skip_synthetic:
        print("\nSynthetic:")
        create_synthetic(args.env, acct_i, args.endpoint, key, args.dry_run)

    print(
        "\n==> Concluído.\n"
        "Os GUIDs acima alimentam os secrets NEW_RELIC_INFRA_ENTITY_GUID (repo de k8s)\n"
        "e NEW_RELIC_LAMBDA_ENTITY_GUID (repo da lambda), usados pelo marcador de deploy."
    )


if __name__ == "__main__":
    main()
