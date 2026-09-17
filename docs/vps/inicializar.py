# Inicialização única da configuração privada de uma VPS dedicada ao BRN Mail.
import base64
import os
import re
import secrets
from pathlib import Path

root = Path('/opt/brnmail')
host = os.environ.get('BRNMAIL_HOST', '')
if not re.fullmatch(r'[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?', host) or '.' not in host:
    raise SystemExit('Defina BRNMAIL_HOST com o hostname real do painel.')
if host.endswith(('seudominio.com', 'example.com', 'example.test')):
    raise SystemExit('Substitua o hostname de exemplo antes de continuar.')
private = root / 'private'
if private.exists() and any(private.iterdir()):
    raise SystemExit('Configuração existente preservada. Inicialização cancelada.')
private.mkdir(mode=0o700, exist_ok=True)
private.chmod(0o700)
(root / 'data').mkdir(mode=0o700, exist_ok=True)
for relative in ['vendor', 'bootstrap-cache', 'storage/app/private',
                 'storage/framework/cache/data', 'storage/framework/sessions',
                 'storage/framework/views', 'storage/logs']:
    (root / 'data' / relative).mkdir(parents=True, exist_ok=True)
for item in (root / 'data').rglob('*'):
    if item.is_dir():
        os.chown(item, 33, 33)
        item.chmod(0o700)

def save(name, value, mode=0o644, uid=0):
    target = private / name
    target.write_bytes(value if isinstance(value, bytes) else value.encode())
    target.chmod(mode)
    os.chown(target, uid, uid)

db = secrets.token_hex(32)
redis = secrets.token_hex(32)
app_key = base64.b64encode(secrets.token_bytes(32)).decode()
save('mysql-password', db)
save('mysql-root-password', secrets.token_hex(32))
save('backup.key', secrets.token_bytes(32), 0o600, 33)
save('redis.conf', f'''bind 0.0.0.0
protected-mode yes
port 6379
requirepass {redis}
appendonly yes
appendfsync everysec
maxmemory 128mb
maxmemory-policy noeviction
dir /data
''')
save('brnmail.env', f'''APP_NAME="BRN Mail"
APP_ENV=production
APP_KEY=base64:{app_key}
APP_DEBUG=false
APP_URL=https://{host}
APP_LOCALE=pt_BR
BRNMAIL_BOOTSTRAP_URL=https://{host}
BRNMAIL_BOOTSTRAP_DB_PORT=33461
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=33461
DB_DATABASE=brnmail
DB_USERNAME=brnmail
DB_PASSWORD={db}
SESSION_DRIVER=file
SESSION_ENCRYPT=true
SESSION_LIFETIME=60
SESSION_COOKIE=brnmail_session
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=16381
REDIS_PASSWORD={redis}
REDIS_PREFIX=brnmail_vps_
MAIL_MAILER=array
BRNMAIL_TRANSPORT=resend
BRNMAIL_EXTERNAL_ENABLED=false
BRNMAIL_LOCAL_DEMO=false
BRNMAIL_SCANNER=clamd
BRNMAIL_CLAMD_HOST=127.0.0.1
BRNMAIL_CLAMD_PORT=13310
RESEND_API_KEY=
RESEND_WEBHOOK_SECRET=
RESEND_TEST_RECIPIENTS=
LOG_CHANNEL=single
LOG_LEVEL=warning
''', 0o600, 33)
(root / 'deploy' / '.env').write_text(f'BRNMAIL_HOST={host}\n')
(root / 'deploy' / '.env').chmod(0o600)
print('Ambiente criado. Segredos não exibidos; acesso externo desativado.')
