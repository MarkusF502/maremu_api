#!/usr/bin/env bash
# deploy-azure.sh — migra a Maremu API do Render para o Azure
# Uso: ./deploy-azure.sh <etapa>
#   setup    registra providers e a extensão do CLI (uma vez por assinatura)
#   infra    cria resource group, Postgres, ACR e ambiente do Container Apps
#   db       copia os dados do Render para o Azure (suspenda o Render antes!)
#   app      builda a imagem e cria o Container App
#   job      cria o job diário de métricas (opcional, requer o comando artisan)
#   deploy   rebuilda e publica uma nova versão (use nos próximos deploys)
#   check    testa o endpoint /up
set -euo pipefail
cd "$(dirname "$0")"   # o script fica na raiz do repo (maremu-api); .env.azure e o build partem daqui

# Segredos ficam em .env.azure (NÃO commitar — adicione ao .gitignore):
#   DB_PASSWORD=...  APP_KEY=...  ANTHROPIC_API_KEY=...  RENDER_DB_URL=...
#   GEMINI_API_KEY=... (opcional)
[[ -f .env.azure ]] && { set -a; source .env.azure; set +a; }

# ---------- Configuração (pode sobrescrever via env) ----------
RG="${RG:-maremu-rg}"
LOC="${LOC:-canadacentral}"               # centralus ficou com ambiente travado (bug do Azure) e a assinatura só permite 1 ambiente por região
DB_SERVER="${DB_SERVER:-maremu-db-ca}"     # nome único global (maremu-db é o servidor antigo em centralus)
DB_NAME="${DB_NAME:-maremu}"
DB_ADMIN="${DB_ADMIN:-maremu}"
PG_VERSION="${PG_VERSION:-16}"            # igual ou maior que a versão do Postgres no Render
ACR_NAME="${ACR_NAME:-maremuacr}"         # nome único global, só minúsculas e números
ENV_NAME="${ENV_NAME:-maremu-env-ca}"        # a assinatura só permite 1 ambiente por região
APP_NAME="${APP_NAME:-maremu-api-ca}"       # maremu-api e maremu-api-v2 ficaram travados em centralus e ocupam os nomes
JOB_NAME="${JOB_NAME:-maremu-metricas}"
API_DIR="${API_DIR:-.}"                   # contexto do build (o próprio repo)
MIN_REPLICAS="${MIN_REPLICAS:-0}"         # 1 elimina cold start, mas cobra 24h
MAX_REPLICAS="${MAX_REPLICAS:-1}"         # >1 só depois de usar `migrate --isolated` no entrypoint
JOB_CRON="${JOB_CRON:-0 6 * * *}"         # cron do Azure é UTC: 06:00 UTC = 03:00 em Brasília
JOB_COMMAND="${JOB_COMMAND:-metricas:agregar}"

DB_HOST="$DB_SERVER.postgres.database.azure.com"
ACR_SERVER="$ACR_NAME.azurecr.io"

# ---------- Utilitários ----------
need() { for v in "$@"; do [[ -n "${!v:-}" ]] || { echo "Defina a variável $v (em .env.azure)" >&2; exit 1; }; done; }

# A senha do Azure exige símbolos; sem encode, caracteres como @ : / # quebram o DB_URL
urlencode() { python3 -c 'import sys, urllib.parse as u; print(u.quote(sys.argv[1], safe=""))' "$1"; }

# Tag única por build: com a mesma tag (ex.: latest) o Container Apps não cria revisão nova
image_tag() { echo "$(git -C "$API_DIR" rev-parse --short HEAD 2>/dev/null || echo build)-$(date +%Y%m%d%H%M%S)"; }

# Build local + push: a assinatura de créditos bloqueia ACR Tasks (az acr build → TasksOperationsNotAllowed)
build_image() {
  local image="$ACR_SERVER/$APP_NAME:$1"
  docker build --platform linux/amd64 -t "$image" "$API_DIR"
  az acr login -n "$ACR_NAME"
  docker push "$image"
}

app_fqdn() {
  echo "$APP_NAME.$(az containerapp env show -g "$RG" -n "$ENV_NAME" --query properties.defaultDomain -o tsv)"
}

# Segredos e env vars compartilhados entre o app e o job
build_config() {
  need APP_KEY DB_PASSWORD ANTHROPIC_API_KEY
  local db_url="pgsql://$DB_ADMIN:$(urlencode "$DB_PASSWORD")@$DB_HOST:5432/$DB_NAME?sslmode=require"
  SECRETS=(
    "app-key=$APP_KEY"
    "db-url=$db_url"
    "anthropic-key=$ANTHROPIC_API_KEY"
  )
  ENV_VARS=(
    APP_NAME=Maremu APP_ENV=production APP_DEBUG=false
    APP_KEY=secretref:app-key
    DB_CONNECTION=pgsql DB_URL=secretref:db-url DB_SSLMODE=require
    LOG_CHANNEL=stderr CACHE_STORE=database QUEUE_CONNECTION=sync FILESYSTEM_DISK=local
    IA_PROVIDER=anthropic ANTHROPIC_API_KEY=secretref:anthropic-key ANTHROPIC_MODEL=claude-haiku-4-5 GEMINI_MODEL=gemini-flash-latest
    VERCEL_PROJECT=maremu FRONTEND_URL=https://maremu.com.br
    "APP_URL=https://$(app_fqdn)"
  )
  # Login no ACR por usuário/senha (admin): sem isso o `containerapp create` tenta managed identity
  REGISTRY_ARGS=(
    --registry-server "$ACR_SERVER"
    --registry-username "$(az acr credential show -n "$ACR_NAME" --query username -o tsv)"
    --registry-password "$(az acr credential show -n "$ACR_NAME" --query 'passwords[0].value' -o tsv)"
  )
  if [[ -n "${GEMINI_API_KEY:-}" ]]; then
    SECRETS+=("gemini-key=$GEMINI_API_KEY")
    ENV_VARS+=(GEMINI_API_KEY=secretref:gemini-key)
  fi
}

# ---------- Etapas ----------
cmd_setup() {
  az extension add --name containerapp --upgrade --only-show-errors
  for p in Microsoft.App Microsoft.OperationalInsights Microsoft.ContainerRegistry Microsoft.DBforPostgreSQL; do
    echo "Registrando $p..."
    az provider register -n "$p" --wait
  done
}

cmd_infra() {
  need DB_PASSWORD
  # O RG já existia em brazilsouth (criado antes da policy barrar a região); a região dele é só metadado
  az group show -n "$RG" -o none 2>/dev/null || az group create -n "$RG" -l "$LOC" -o none

  # 0.0.0.0 = libera QUALQUER serviço do Azure (inclusive de outras contas), não só os seus.
  # Necessário porque o IP de saída do Container Apps não é fixo. A proteção real é senha + SSL.
  # Cada recurso só é criado se ainda não existir: dá pra rodar `infra` de novo após uma falha no meio
  az postgres flexible-server show -g "$RG" -n "$DB_SERVER" -o none 2>/dev/null ||
    az postgres flexible-server create -g "$RG" -n "$DB_SERVER" -l "$LOC" \
      --tier Burstable --sku-name Standard_B1ms --storage-size 32 --version "$PG_VERSION" \
      --admin-user "$DB_ADMIN" --admin-password "$DB_PASSWORD" \
      --public-access 0.0.0.0 -o none
  az postgres flexible-server db show -g "$RG" -s "$DB_SERVER" -n "$DB_NAME" -o none 2>/dev/null ||
    az postgres flexible-server db create -g "$RG" -s "$DB_SERVER" -n "$DB_NAME" -o none

  # Criar com o mesmo nome só atualiza o IP da regra
  local my_ip; my_ip="$(curl -fsS https://api.ipify.org)"
  az postgres flexible-server firewall-rule create -g "$RG" -s "$DB_SERVER" \
    -n meu-ip --start-ip-address "$my_ip" --end-ip-address "$my_ip" -o none

  az acr show -g "$RG" -n "$ACR_NAME" -o none 2>/dev/null ||
    az acr create -g "$RG" -n "$ACR_NAME" -l "$LOC" --sku Basic --admin-enabled true -o none
  # O create pode deixar o ambiente em "Failed" por erro transitório do Azure; um update sem
  # mudanças reprovisiona sem apagar nada
  local env_state
  env_state="$(az containerapp env show -g "$RG" -n "$ENV_NAME" --query properties.provisioningState -o tsv 2>/dev/null || true)"
  if [[ -z "$env_state" ]]; then
    # Sem --environment-mode, o az 2.90 cria em modo Express (preview), que recusa managed identity
    # e quebra o `containerapp create`/`update` — WorkloadProfiles é o modo padrão com perfil Consumption
    az containerapp env create -g "$RG" -n "$ENV_NAME" -l "$LOC" --environment-mode WorkloadProfiles -o none
  elif [[ "$env_state" != "Succeeded" ]]; then
    echo "Ambiente $ENV_NAME em estado $env_state, reprovisionando..."
    az containerapp env update -g "$RG" -n "$ENV_NAME" -o none
  fi
  echo "Infra criada."
}

cmd_db() {
  need RENDER_DB_URL DB_PASSWORD
  export PGPASSWORD="$DB_PASSWORD"   # usada só na conexão com o Azure (a URL do Render já tem senha)
  local azure_conn="host=$DB_HOST port=5432 dbname=$DB_NAME user=$DB_ADMIN sslmode=require"

  echo "Postgres do Render: $(psql "$RENDER_DB_URL" -Atc 'show server_version')"
  echo "pg_dump local:      $(pg_dump --version)"
  echo "O pg_dump local precisa ser da mesma versão principal do Render ou mais novo. Enter para continuar."
  read -r

  local exts
  exts="$(psql "$RENDER_DB_URL" -Atc "select coalesce(string_agg(extname, ','), '') from pg_extension where extname <> 'plpgsql'")"
  if [[ -n "$exts" ]]; then
    echo "Liberando extensões no Azure: $exts"
    az postgres flexible-server parameter set -g "$RG" -s "$DB_SERVER" \
      --name azure.extensions --value "$exts" -o none
  fi

  pg_dump "$RENDER_DB_URL" -Fc --no-owner --no-acl -f maremu.dump
  pg_restore --no-owner --no-acl -d "$azure_conn" maremu.dump \
    || echo "⚠️  pg_restore reportou erros — revise a saída acima antes de seguir."

  # Contagem exata de linhas por tabela nos dois bancos
  local q="select table_name, (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', table_schema, table_name), false, true, '')))[1]::text
           from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by 1"
  if diff <(psql "$RENDER_DB_URL" -Atc "$q") <(psql "$azure_conn" -Atc "$q"); then
    echo "✅ Todas as tabelas têm a mesma contagem de linhas."
  else
    echo "❌ Contagens diferentes (acima). Não faça a virada ainda."
  fi
}

cmd_app() {
  build_config
  local tag; tag="$(image_tag)"
  build_image "$tag"
  az containerapp create -g "$RG" -n "$APP_NAME" --environment "$ENV_NAME" \
    --image "$ACR_SERVER/$APP_NAME:$tag" "${REGISTRY_ARGS[@]}" \
    --ingress external --target-port 80 \
    --min-replicas "$MIN_REPLICAS" --max-replicas "$MAX_REPLICAS" \
    --cpu 0.5 --memory 1.0Gi \
    --secrets "${SECRETS[@]}" --env-vars "${ENV_VARS[@]}" -o none
  cmd_check
}

cmd_job() {
  build_config
  local image
  image="$(az containerapp show -g "$RG" -n "$APP_NAME" --query 'properties.template.containers[0].image' -o tsv)"
  # --command substitui o ENTRYPOINT do Dockerfile. Se o job falhar com
  # "Could not open input file: artisan", troque por: --command sh --args -c "cd /var/www/html && php artisan $JOB_COMMAND"
  az containerapp job create -g "$RG" -n "$JOB_NAME" --environment "$ENV_NAME" \
    --trigger-type Schedule --cron-expression "$JOB_CRON" \
    --replica-timeout 1800 --replica-retry-limit 1 --parallelism 1 --replica-completion-count 1 \
    --image "$image" "${REGISTRY_ARGS[@]}" \
    --cpu 0.5 --memory 1.0Gi \
    --command php --args artisan "$JOB_COMMAND" \
    --secrets "${SECRETS[@]}" --env-vars "${ENV_VARS[@]}" -o none
  echo "Job criado. Para testar agora: az containerapp job start -g $RG -n $JOB_NAME"
}

cmd_deploy() {
  local tag; tag="$(image_tag)"
  build_image "$tag"
  az containerapp update -g "$RG" -n "$APP_NAME" --image "$ACR_SERVER/$APP_NAME:$tag" -o none
  if az containerapp job show -g "$RG" -n "$JOB_NAME" &>/dev/null; then
    az containerapp job update -g "$RG" -n "$JOB_NAME" --image "$ACR_SERVER/$APP_NAME:$tag" -o none
  fi
  cmd_check
}

cmd_check() {
  local url; url="https://$(app_fqdn)/up"
  if curl -fsS --max-time 90 "$url" >/dev/null; then echo "✅ $url respondeu"; else echo "❌ $url não respondeu"; exit 1; fi
}

case "${1:-}" in
  setup|infra|db|app|job|deploy|check) "cmd_$1" ;;
  *) sed -n '2,11p' "$0"; exit 1 ;;
esac