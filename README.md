# SoftNexa — Official Website & CRM Control Panel

> Modern business software, web applications, and digital platforms built to streamline operations and scale businesses.

![SoftNexa](assets/logo-mark.png)

---

## 🚀 Overview

SoftNexa is a full-featured web platform and business suite designed with high-performance vanilla web technologies and an autonomous first-party backend. It includes an interactive scope builder, sector demos, intelligent lead capture, and an administrative CRM dashboard.

---

## ✨ Key Features

### 🌐 Frontend & User Experience (`index.html`)
- **Interactive Project Scope Builder**: Step-by-step cost and timeline estimator with instant lead qualification.
- **Sector & Industry Demos**: Live interactive workflow previews across 12+ industries (Logistics, Healthcare, Retail, Manufacturing, etc.).
- **Dynamic Conversion Engine**: Smart popups including 2026 tech trends, service offer triggers, and exit-intent prompts.
- **Privacy-First Telemetry**: First-party analytics tracking (no third-party cookies or external trackers required).
- **Responsive & Dual Theme**: Adaptive dark/light design system with zero framework overhead.

### ⚙️ Backend API (`api/`)
- **Automated Lead Scoring**: Server-side engine evaluating team size, urgency, selected modules, and corporate email signals (0–100 score).
- **Rate Limiting & Spam Defense**: Built-in IP rate limiter and honeypot bot trap.
- **Instant Email Alerts**: Automatic priority notifications (`[HOT 85]`) sent directly to your inbox.
- **Dynamic Settings API**: Real-time sync of trial parameters and founding client availability.

### 📊 Admin Control Panel (`admin/`)
- **Overview Dashboard**: High-level KPIs, daily visitor trends, and step-by-step conversion funnel analysis.
- **CRM Lead Pipeline**: Manage leads across stages (`new`, `contacted`, `qualified`, `proposal`, `won`, `lost`), add internal notes, and export to CSV.
- **Traffic & Interaction Analytics**: Live active visitor tracker, device & regional distribution, hour-of-week heatmaps, and CTA click statistics.
- **Platform Management**: Manage promotional offers, purge old telemetry logs, and update admin credentials securely.

---

## 🛠️ Project Structure

```
softnexa/
├── index.html              # Website, interactive scope builder, and demos
├── assets/                 # HD imagery, logos, and favicons
├── api/                    # Backend endpoints
│   ├── config.php          # Database configuration & core helpers
│   ├── contact.php         # Lead intake & scoring handler
│   ├── track.php           # First-party visitor tracking
│   ├── settings.php        # Dynamic frontend configuration
│   └── db.sql              # MySQL database schema
└── admin/                  # CRM & Analytics control panel
    ├── _guard.php          # Auth guard, CSRF security & layout
    ├── login.php / logout  # Admin session management
    ├── dashboard.php       # Funnel & traffic overview
    ├── leads.php           # Pipeline management & CSV export
    ├── analytics.php       # Live visitors & interaction heatmaps
    └── settings.php        # System configuration & housekeeping
```

---

## 📖 Deployment Guide

For step-by-step instructions on setting up the MySQL database, configuring `api/config.php`, and deploying to Hostinger or cPanel, refer to the [Setup Guide](README-SETUP.md).

---

## 🔒 Security & Privacy

- **Password Security**: Admin authentication uses modern `bcrypt` hashing via `password_verify()`.
- **CSRF Protection**: All administrative actions and state changes are guarded with anti-CSRF tokens.
- **SQL Injection Prevention**: Built entirely with PDO prepared statements.
- **Privacy Compliance**: All visitor tracking is first-party with session-isolated IDs and zero third-party data sharing.

---

## 📄 License
Copyright © SoftNexa. All rights reserved.
