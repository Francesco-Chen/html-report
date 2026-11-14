# HTML Report Hub

A lightweight, self-hosted, single-file PHP dashboard for collecting, organizing, and browsing interactive HTML reports. Perfect for CI/CD test results (pytest-html, Playwright, Allure), data science notebooks (Jupyter, Pandas Profiling), daily build monitoring, and automated analytics summaries.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue.svg)](https://www.php.net/)
[![Database](https://img.shields.io/badge/database-SQLite-green.svg)](https://www.sqlite.org/)
[![License](https://img.shields.io/badge/license-MIT-purple.svg)](LICENSE)
[![Zero Dependencies](https://img.shields.io/badge/dependencies-none-brightgreen.svg)](#)

---

## 🌟 Highlights

- **Single-File Simplicity**: Entire backend and frontend logic encapsulated in `index.php`. No Composer, no heavy framework overhead.
- **Card-Based Dashboard**: Responsive 1:1 square card layout with smooth glassmorphism, dynamic gradients, and custom cover images.
- **Dual Operating Modes**:
  - **View Mode**: Clean browsing experience; click any card to open the report in a new tab.
  - **Manage Mode**: Interactive management with drag-and-drop reordering (auto-saved via SortableJS), metadata editing, source code inspection, and permanent deletion.
- **Dedicated Card API Tokens**:
  - Every card has an isolated 64-character token.
  - **Security Isolation**: A card token can only update its own card—it cannot read, overwrite, or delete any other report.
  - Enables secure automated pushes from GitHub Actions, GitLab CI, Python scripts, and cron jobs.
- **Online HTML Source Editor**: Inspect or tweak HTML report code directly in the browser with an edit-and-overwrite toggle.
- **Auto-Extraction**: Automatically extracts `<title>` tags from uploaded HTML documents if title is left blank.
- **Security Hardened**:
  - Physical files stored in separate `document/` and `covers/` directories.
  - Built-in `.htaccess` rules prevent Apache from executing scripts inside upload directories.
  - Strict path traversal defenses (`safeUnlink`) and image MIME verification.

---

## 🚀 Quick Start

### Requirements
- **PHP 7.4+** or **PHP 8.x**
- PHP PDO SQLite extension (`pdo_sqlite`)
- Writable permissions for the project directory

### 1. Run with PHP Built-in Server
Clone the repository and start the server:

```bash
git clone https://github.com/Francesco-Chen/html-report.git
cd html-report
php -S 0.0.0.0:8000 index.php
```

Open your browser and navigate to `http://localhost:8000`.

### 2. Nginx Configuration (Optional)
If deploying under Nginx + PHP-FPM:

```nginx
server {
    listen 80;
    server_name reports.example.com;
    root /var/www/html-report-hub;
    index index.php;

    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Block PHP execution in upload directories
    location ~* ^/(document|covers)/.*\.php$ {
        deny all;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## 🔌 Automated API Push Guide

Each report card provides a dedicated 64-character token. You can update reports automatically via `POST` requests.

The token can be passed using any of the following:
- HTTP Header: `Authorization: Bearer <CARD_TOKEN>`
- HTTP Header: `X-Card-Token: <CARD_TOKEN>`
- Form field: `token=<CARD_TOKEN>`

### 1. Upload HTML File & Cover via cURL

```bash
curl -X POST "http://your-server:8000/index.php" \
  -H "Authorization: Bearer YOUR_CARD_TOKEN_HERE" \
  -F "html_file=@/path/to/report.html" \
  -F "cover_image=@/path/to/cover.png" \
  -F "title=2026 Q4 Core Analytics & Metrics Report"
```

### 2. Push Raw HTML String via cURL

```bash
curl -X POST "http://your-server:8000/index.php" \
  -H "X-Card-Token: YOUR_CARD_TOKEN_HERE" \
  -F "raw_html=<!DOCTYPE html><html><head><title>Daily Build Status</title></head><body><h1>All Tests Passed</h1></body></html>"
```

### 3. Push from Python Script

```python
import requests

API_ENDPOINT = "http://your-server:8000/index.php"
CARD_TOKEN = "YOUR_CARD_TOKEN_HERE"

headers = {
    "Authorization": f"Bearer {CARD_TOKEN}"
}

# Example 1: Upload a file
with open("test_report.html", "rb") as html_f:
    files = {"html_file": html_f}
    data = {"title": "CI/CD Test Report"}
    response = requests.post(API_ENDPOINT, headers=headers, data=data, files=files)
    print(response.json())

# Example 2: Push raw HTML directly
response = requests.post(
    API_ENDPOINT,
    headers=headers,
    data={
        "raw_html": "<html><body><h1>Automated Build #42 Succeeded</h1></body></html>",
        "title": "Build #42 Status"
    }
)
print(response.json())
```

---

## ⚙️ Configuration

Environment variables can be configured via web server environment or `.env` loader:

| Variable | Default | Description |
|---|---|---|
| `APP_TIMEZONE` | `UTC` | Timezone for card timestamps and filenames (e.g. `Asia/Shanghai`, `America/New_York`, `UTC`) |

---

## 📂 Project Structure

```
html-report-hub/
├── index.php              # Full application (routing, controllers, UI, API)
├── document/              # Uploaded HTML reports directory
│   ├── .htaccess          # Security execution lock
│   └── .gitkeep
├── covers/                # Uploaded card cover images
│   ├── .htaccess          # Security execution lock
│   └── .gitkeep
├── .gitignore             # Git ignore rules for database and uploads
├── README.md              # Project documentation
└── LICENSE                # MIT License
```

---

## 🔒 Security

1. **Upload Execution Prevention**: Upload directories (`document/`, `covers/`) contain `.htaccess` rules that disable script execution.
2. **Directory Traversal Protection**: All file deletions use `realpath` checks against allowed base directories before unlinking.
3. **MIME Verification**: Uploaded images are validated with `getimagesize()` against allowed MIME types to prevent disguised scripts.
4. **Token Isolation**: Card tokens are cryptographically generated (64-character hex via `random_bytes(32)`) and scoped strictly to individual cards.

---

## 📄 License

This project is open-sourced under the [MIT License](LICENSE).
