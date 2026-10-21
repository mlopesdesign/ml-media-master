#!/usr/bin/env bash
# ML Release Gate — trava de publicação dos plugins ML Lopes Design.
# Uso: gate.sh <slug> <zip-novo>
# Reprova (exit 1) se: estrutura do ZIP errada, identidade (pasta/arquivo/nome/text domain)
# diferente da versão anterior publicada, versão não maior, erro fatal com o plugin SOZINHO
# (ativação, site, telas do admin, cron), ou se a atualização não cair na mesma pasta.
# Variáveis opcionais: WP_SRC (núcleo WP local), SQLITE_SRC (plugin sqlite local), WPCLI, PREV_ZIP.
set -uo pipefail
SLUG="${1:?slug}"; ZIP="$(readlink -f "${2:?zip}")"
WORK="$(mktemp -d)"; FAIL=0; PORT="${PORT:-8765}"
WPCLI="${WPCLI:-$WORK/wp-cli.phar}"
ok(){ echo "  ✔ $*"; }
bad(){ echo "  ✘ $*"; echo "::error::$*"; FAIL=1; }
hdr(){ unzip -p "$1" "$SLUG/$SLUG.php" | head -40 | grep -iE "^[[:space:]/*#@]*$2:" | head -1 | sed -E "s/.*$2:[[:space:]]*//I" | tr -d '\r' | sed 's/[[:space:]]*$//'; }

echo "== 1. Estrutura do ZIP"
roots=$(unzip -Z1 "$ZIP" | cut -d/ -f1 | sort -u)
[ "$roots" = "$SLUG" ] && ok "raiz única $SLUG/" || bad "raiz do ZIP = [$roots], esperado [$SLUG]"
for f in "$SLUG.php" readme.txt uninstall.php; do
  unzip -Z1 "$ZIP" | grep -qx "$SLUG/$f" && ok "$f presente" || bad "falta $SLUG/$f"
done
unzip -Z1 "$ZIP" | grep -qE "(^|/)(\.git|node_modules|__MACOSX|\.DS_Store)(/|$)|\.zip$|\\\\" && bad "lixo no ZIP (.git, node_modules, zip, barra invertida…)" || ok "sem lixo"
unzip -Z1 "$ZIP" | grep -qx "$SLUG/$SLUG/" && bad "pasta duplicada $SLUG/$SLUG/" || true
if unzip -p "$ZIP" | grep -aqE "gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}"; then bad "token do GitHub dentro do ZIP"; else ok "sem tokens"; fi
NEWV=$(hdr "$ZIP" Version); NAME=$(hdr "$ZIP" "Plugin Name"); TD=$(hdr "$ZIP" "Text Domain")
echo "  versão $NEWV · nome '$NAME' · text domain '$TD'"

echo "== 2. Identidade × versão anterior publicada"
PREV="${PREV_ZIP:-}"
if [ -z "$PREV" ]; then
  url=$(curl -sfL "https://api.github.com/repos/mlopesdesign/$SLUG-releases/releases/latest" | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(($j["assets"]??[]) as $a){ if(substr($a["name"],-4)===".zip"){ echo $a["browser_download_url"]; break; } }')
  [ -n "$url" ] && curl -sfL -o "$WORK/prev.zip" "$url" && PREV="$WORK/prev.zip"
fi
if [ -n "$PREV" ] && [ -f "$PREV" ]; then
  OLDV=$(hdr "$PREV" Version)
  [ "$(unzip -Z1 "$PREV" | cut -d/ -f1 | sort -u)" = "$SLUG" ] && ok "anterior usa a mesma pasta" || bad "pasta do ZIP anterior difere"
  [ "$(hdr "$PREV" "Plugin Name")" = "$NAME" ] && ok "nome do plugin igual" || bad "nome mudou: '$(hdr "$PREV" "Plugin Name")' → '$NAME'"
  [ "$(hdr "$PREV" "Text Domain")" = "$TD" ] && ok "text domain igual" || bad "text domain mudou"
  if php -r "exit(version_compare('$NEWV','$OLDV','>')?0:1);"; then ok "versão $OLDV → $NEWV"
  elif [ "${GATE_MODE:-release}" = pr ] && [ "$NEWV" = "$OLDV" ]; then echo "  (PR sem subir versão: $NEWV = publicada — o release exigirá versão maior)"
  else bad "versão $NEWV não é maior que a publicada $OLDV"; fi
else
  echo "  (sem versão anterior publicada — primeiro lançamento)"
fi

echo "== 3. WordPress limpo, plugin SOZINHO"
[ -f "$WPCLI" ] || curl -sSLo "$WPCLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
WP="$WORK/wp"; mkdir -p "$WP"
wp(){ php -d memory_limit=512M "$WPCLI" --allow-root --path="$WP" "$@"; }
if [ -n "${WP_SRC:-}" ]; then cp -r "$WP_SRC"/. "$WP"/; rm -f "$WP/wp-config.php"; rm -rf "$WP/wp-content/plugins"/* "$WP/wp-content/database" "$WP/wp-content/db.php" "$WP/wp-content/debug.log"; else wp core download --locale=pt_BR --quiet || wp core download --quiet; fi
# WordPress limpo de verdade: sem os plugins que vêm junto com o núcleo (Akismet, Hello Dolly).
find "$WP/wp-content/plugins" -mindepth 1 -maxdepth 1 ! -name index.php -exec rm -rf {} +
if [ -n "${SQLITE_SRC:-}" ]; then cp -r "$SQLITE_SRC" "$WP/wp-content/plugins/sqlite-database-integration"; else
  curl -sSLo "$WORK/sq.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip && unzip -q "$WORK/sq.zip" -d "$WP/wp-content/plugins/"; fi
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP/wp-content/plugins/sqlite-database-integration#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
  "$WP/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP/wp-content/db.php"
wp config create --dbname=wp --dbuser=x --dbpass=x --skip-check --quiet --extra-php <<'PHP'
define('WP_DEBUG', true); define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true); define('AUTOMATIC_UPDATER_DISABLED', true);
PHP
wp core install --url="http://127.0.0.1:$PORT" --title=Gate --admin_user=gate --admin_password="$(head -c 18 /dev/urandom | base64)" --admin_email=gate@example.test --skip-email --quiet
PL="$WP/wp-content/plugins"; LOG="$WP/wp-content/debug.log"
fatal_check(){ if [ -f "$LOG" ] && grep -qE "PHP (Fatal|Parse) error" "$LOG"; then bad "$1: $(grep -E 'PHP (Fatal|Parse) error' "$LOG" | tail -1 | cut -c1-300)"; : > "$LOG"; return 1; fi; return 0; }

php -S "127.0.0.1:$PORT" -t "$WP" >/dev/null 2>&1 & SRV=$!; trap 'kill $SRV 2>/dev/null; rm -rf "$WORK"' EXIT; sleep 1
COOKIE="$WORK/c.txt"
wp eval '$u=get_user_by("login","gate"); $e=time()+3600; foreach([AUTH_COOKIE=>"auth",SECURE_AUTH_COOKIE=>"secure_auth",LOGGED_IN_COOKIE=>"logged_in"] as $n=>$s){ echo "127.0.0.1\tFALSE\t/\tFALSE\t$e\t$n\t".wp_generate_auth_cookie($u->ID,$e,$s)."\n"; }' > "$COOKIE"
hit(){ # hit <rótulo> <caminho>
  local code body; body="$WORK/body.html"
  code=$(curl -s -o "$body" -w '%{http_code}' -b "$COOKIE" "http://127.0.0.1:$PORT$2")
  if [ "$code" -ge 500 ] || grep -qiE "erro crítico|critical error|Fatal error|Parse error" "$body"; then bad "$1 ($2) → HTTP $code com erro"; elif ! fatal_check "$1"; then :; else ok "$1 ($2) → $code"; fi; }

test_round(){ # test_round <rótulo>
  echo "-- $1"
  if wp plugin activate "$SLUG" 2>"$WORK/act.err"; then fatal_check "ativação" && ok "ativado sozinho"; else bad "ativação falhou: $(head -c 300 "$WORK/act.err")"; return; fi
  active=$(wp plugin list --status=active --field=name | grep -v '^sqlite-database-integration$' | tr '\n' ' ')
  [ "$active" = "$SLUG " ] && ok "único plugin ativo" || bad "plugins ativos: $active"
  hit "site" "/"; hit "admin" "/wp-admin/"
  pages=$(wp eval 'require_once ABSPATH."wp-admin/includes/admin.php"; wp_set_current_user(1); set_current_screen("dashboard"); do_action("admin_menu"); global $menu,$submenu; $o=[]; foreach((array)$menu as $m){$o[]=$m[2];} foreach((array)$submenu as $s){foreach($s as $i){$o[]=$i[2];}} foreach(array_unique($o) as $p){ if($p && strpos($p,".php")===false && strpos($p,"/")===false && strpos($p,"separator")===false) echo $p,"\n"; }' 2>>"$LOG")
  [ -n "$pages" ] || echo "  (plugin sem telas próprias no admin)"
  for p in $pages; do hit "tela $p" "/wp-admin/admin.php?page=$p"; done
  hit "plugins.php" "/wp-admin/plugins.php"
  wp cron event run --due-now >/dev/null 2>>"$LOG"; fatal_check "cron" && ok "cron executado"
}

if [ -n "$PREV" ] && [ -f "$PREV" ]; then
  unzip -q "$PREV" -d "$PL"; SAVED=$FAIL; test_round "versão anterior $OLDV (base — só referência, não reprova)"
  FAILBASE=$FAIL; FAIL=$SAVED; : > "$LOG"
  WASACTIVE=0; wp plugin is-active "$SLUG" 2>/dev/null && WASACTIVE=1
  echo "-- atualização $OLDV → $NEWV pelo instalador do WordPress"
  wp plugin install "$ZIP" --force --quiet 2>"$WORK/up.err" || bad "instalação da atualização falhou: $(head -c 300 "$WORK/up.err")"
  dirs=$(ls "$PL" | grep -vxE 'sqlite-database-integration|index\.php' | tr '\n' ' ')
  [ "$dirs" = "$SLUG " ] && ok "atualizou na mesma pasta ($SLUG/), sem instalação nova" || bad "pastas após atualização: $dirs"
  [ "$(wp plugin get "$SLUG" --field=version 2>/dev/null)" = "$NEWV" ] && ok "versão instalada $NEWV" || bad "versão instalada difere"
  if [ $WASACTIVE -eq 1 ]; then wp plugin is-active "$SLUG" 2>/dev/null && ok "continuou ativo após atualizar" || bad "ficou inativo após atualizar"; fi
  fatal_check "atualização"
  wp plugin deactivate "$SLUG" --quiet 2>/dev/null; : > "$LOG"
  [ $FAILBASE -ne $SAVED ] && echo "  (aviso: a versão anterior publicada tem falhas — esta versão precisa corrigi-las)"
else
  unzip -q "$ZIP" -d "$PL"
fi
test_round "versão nova $NEWV sozinha"

echo
if [ $FAIL -eq 0 ]; then echo "APROVADO — $SLUG $NEWV"; else echo "REPROVADO — $SLUG $NEWV não pode ser publicado"; fi
exit $FAIL
