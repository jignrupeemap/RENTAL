# Rental website

Rental, PG and lease marketplace. PHP 8.2+ and MySQL 8 (or MariaDB 10.6+),
no framework, no Node.js or Composer needed to run it.

```
rental/
├── client/          front-end notes; pages are server-rendered (see client/README.md)
│   └── src/
├── server/          the PHP application
│   ├── public/      web root: index.php, CSS/JS, uploads (Nginx points here)
│   ├── app/         controllers, services, core
│   ├── views/       page templates
│   ├── config/      app.php, env.example.php → env.php (secrets, not in git)
│   ├── database/    migrations and install.sql
│   ├── bin/         migrate, cron, backup, check, create-admin ...
│   ├── deploy/      Nginx site, PHP settings, deploy.sh for the VPS
│   ├── docs/        architecture, deployment, security, testing
│   └── tests/
├── .github/workflows/deploy.yml   auto-deploy to the VPS on push to main
├── .gitignore
└── README.md
```

## Deploy

- **Hostinger VPS from GitHub** (recommended): [server/docs/DEPLOY-HOSTINGER-VPS.md](server/docs/DEPLOY-HOSTINGER-VPS.md)
- Hostinger shared hosting (hPanel, zip upload): [server/docs/DEPLOYMENT.md](server/docs/DEPLOYMENT.md)

## Run locally

```bash
cd server
cp config/env.example.php config/env.php   # set APP_ENV=development and DB_* values
php bin/migrate.php
php -S 127.0.0.1:8088 -t public public/index.php
```

More: [server/README.md](server/README.md)
