# Zoho Automated Reply Drafts (Laravel)

This app suggests replies to incoming Zoho Mail emails based on your previous replies, and saves them as draft replies to the original emails.

## Setup

1. Copy env file and set app URL
```
cp .env.example .env
php artisan key:generate
```

2. Configure Zoho OAuth in `.env` (values below are examples)
```
ZOHO_CLIENT_ID=xxx
ZOHO_CLIENT_SECRET=xxx
ZOHO_REDIRECT_URI=${APP_URL}/zoho/callback
ZOHO_BASE_ACCOUNTS=https://accounts.zoho.com
ZOHO_BASE_API=https://mail.zoho.com/api
ZOHO_SCOPE=ZohoMail.messages.ALL,ZohoMail.accounts.READ
# Optional
ZOHO_REFRESH_TOKEN=
ZOHO_ACCOUNT_ID=
```

3. Migrate database
```
php artisan migrate
```

4. Connect your Zoho account
- Start the server, then visit `/zoho/connect`
```
php artisan serve
```

5. Run the generator (creates drafts)
```
php artisan zoho:generate-drafts --limit=20
```

Drafts will be saved in Zoho as replies to the source messages.

## Scheduling
The command is scheduled every 10 minutes via `app/Console/Kernel.php`. Ensure your cron runs `php artisan schedule:run` per minute.

## Notes
- The suggestion engine uses cosine similarity over a simple term-frequency vector of past sent emails.
- For AI-generated replies, add OpenAI credentials:
```
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
# Optional
OPENAI_TEMPERATURE=0.3
OPENAI_MAX_TOKENS=500
OPENAI_BASE_URL=https://api.openai.com
```
If `OPENAI_API_KEY` is set, the app will use OpenAI first and fall back to the similarity engine.
