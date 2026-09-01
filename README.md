
# 🌾 AGRI-AKAP — Backend (Laravel 11 REST API)

> **Agricultural Assistance and Knowledge Access Portal** > **Institution:** Isabela State University – Echague Campus | CCSICT  
> **Target Agency:** Municipal Agriculture Office (MAO) of Echague, Isabela  
> **Degree Program:** BSIT – Web & Mobile Applications Track  

---

## 👥 Proponents & Capstone Group Members

* **John Mitchel M. Dupitas** — *Lead Full-Stack Architect & Project Lead* ([@JohnMitchelDupitas](https://github.com/JohnMitchelDupitas))
* **Justin R. Iddurut** — *Front-End Developer & UI/UX Lead* ([@IDDUJUSTIN](https://github.com/IDDUJUSTIN))
* **Dave Raphael M. Ignacio** — *Quality Assurance & Documentation Lead*

---

## 📌 Backend System Overview

This repository contains the core application logic, database schemas, and RESTful API endpoints for **AGRI-AKAP**. Built on Laravel 11, it processes RSBSA registries, manages subsidy allocations, processes offline sync payloads, and integrates with external communication services.

### ⚙️ Core Backend Capabilities
* **RSBSA Relational Database Schema:** Manages 15+ relational database tables including farmers, parcel plots, subsidy distributions, and priority flags.
* **Anti-Fraud Duplicate Logic:** Composite database constraints (`farmer_id` + `program_id`) prevent double-claiming of government subsidies.
* **Sanctum API Authentication:** Secure Bearer Token-based API access for administrative users and mobile field technicians.
* **Semaphore SMS Gateway:** Automated broadcast engine for pickup schedules and meteorological advisories.

---

## 🔗 Related Repositories

* **Frontend Repository (Ionic Vue 3 PWA):** 👉 [https://github.com/JohnMitchelDupitas/agri-akap-frontend](https://github.com/JohnMitchelDupitas/agri-akap-frontend)

---

## 🛠️ Backend Tech Stack

* **Framework:** Laravel v11.x
* **Runtime Environment:** PHP 8.2+
* **Database Engine:** MariaDB v10.11+ / MySQL
* **Authentication:** Laravel Sanctum
* **ORM:** Eloquent
* **Integrations:** Semaphore SMS API, Open-Meteo Weather API, Bagyo API (advisories), PAGASA Panahon radar overlay

---

## Railway deployment — weather scheduler

The Laravel scheduler in `routes/console.php` runs daily/hourly Open-Meteo syncs. The web service (`bin/start.sh`) does **not** run the scheduler automatically.

On Railway, add a **Cron** service (or second service) that runs every minute:

```bash
php artisan schedule:run
```

Or use `bin/schedule.sh`. After deploy, backfill weather caches once:

```bash
php artisan weather:sync-all
```

Optional env vars for radar/advisories:

| Variable | Default |
|----------|---------|
| `PAGASA_RADAR_ENABLED` | `true` |
| `PAGASA_PANAHON_BASE_URL` | `https://cdn.panahon.gov.ph` |
| `BAGYO_API_BASE_URL` | `https://api.bagyo.io` |

---

## 📂 Directory Structure

```text
agri-akap-backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/   # Auth, Farmer, Subsidy, and SMS Controllers
│   │   └── Middleware/    # Sanctum authentication & Role validation
│   └── Models/            # Eloquent ORM Models & relationships
├── database/
│   ├── migrations/        # RSBSA relational database schemas
│   └── seeders/           # Initial demo records and default admin users
├── routes/
│   └── api.php            # Endpoint routes (/api/v1/...)
├── artisan                # Laravel CLI executable
└── composer.json          # PHP package dependencies
