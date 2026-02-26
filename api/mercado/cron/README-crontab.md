# SuperBora Cron Jobs

## Instalar no servidor

```bash
# Criar diretorio de logs
sudo mkdir -p /var/log/superbora
sudo chown www-data:www-data /var/log/superbora

# Editar crontab do www-data
sudo crontab -u www-data -e
```

## Crontab entries

```crontab
# SuperBora — Liberar repasses (cada 5 min)
*/5 * * * * php /var/www/html/api/mercado/cron/liberar-repasses.php >> /var/log/superbora/cron-repasses.log 2>&1

# SuperBora — Expirar cashback (diario 02:00)
0 2 * * * php /var/www/html/api/mercado/cron/expirar-cashback.php >> /var/log/superbora/cron-cashback.log 2>&1

# SuperBora — Limpar carrinhos abandonados (diario 03:00)
0 3 * * * php /var/www/html/api/mercado/cron/limpar-carrinhos.php >> /var/log/superbora/cron-carrinhos.log 2>&1

# Log rotation (semanal)
0 0 * * 0 find /var/log/superbora/ -name "*.log" -size +10M -exec truncate -s 0 {} \;
```

## Testar manualmente

```bash
php /var/www/html/api/mercado/cron/liberar-repasses.php
php /var/www/html/api/mercado/cron/expirar-cashback.php
php /var/www/html/api/mercado/cron/limpar-carrinhos.php
```

## Variáveis de ambiente necessárias (.env)

```env
# SMTP para emails transacionais
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=noreply@superbora.com.br
SMTP_PASS=app-password-here
SMTP_FROM_EMAIL=noreply@superbora.com.br
SMTP_FROM_NAME=SuperBora

# Stripe (para app mobile)
# Definir no eas.json ou .env do Expo
EXPO_PUBLIC_STRIPE_PUBLISHABLE_KEY=pk_live_...
EXPO_PUBLIC_STRIPE_MERCHANT_ID=merchant.com.superbora.app
```
