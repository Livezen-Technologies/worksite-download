<?php

namespace App\Database;

/**
 * The WorkSuite Server download page's installation guide and requirements: the one-command
 * installer (/install.sh). Used by the catalog seeder (new deployments) and the
 * ServerInstallGuide migration (existing ones). Editable afterwards in /admin.
 * Lists, not tables: SafeMarkdown renders no tables.
 */
final class ServerInstallGuide
{
    public const GUIDE = <<<'MD'
## Install WorkSuite Server with one command

You need a **WorkSuite licence key** (from your WorkSuite account or reseller), a **fresh server**, and a **domain name** pointing at it.

```bash
curl -fsSL https://download.worksuitecloud.com/install.sh -o install.sh
sudo bash install.sh --license-key WS-XXXX-XXXX-XXXX-XXXX-XXXX --domain erp.example.com --admin-email you@example.com
```

Leave out any option and the installer asks for it. It takes 10–20 minutes.

### What the installer does

1. Checks your licence key with the WorkSuite Download Center — a wrong or expired key stops it before anything is installed.
2. Installs Nginx, PHP 8.3-FPM, MariaDB, Redis, Node.js 20 and Certbot.
3. Downloads WorkSuite Server for your licence's edition and **verifies it**: the release manifest's Ed25519 signature against the WorkSuite release key, the package's SHA-256 against the manifest, and every file against `files.sha256`. Nothing unsigned is installed.
4. Creates the database, the configuration, and the first administrator (asked to change the password at first sign-in).
5. **Activates your licence on this server.**
6. Sets up the updater agent, background jobs, nightly backups, the firewall and a Let's Encrypt certificate.

The administrator's temporary password and the database password are saved in `/root/worksuite-credentials.txt` (root only).

### Supported servers

- **Ubuntu 22.04 LTS and 24.04 LTS** — recommended.
- **Debian 12.**
- **RHEL, Rocky Linux, AlmaLinux and CentOS Stream 9** — PHP from Remi; SELinux stays on (the installer sets its booleans).
- **CentOS 7** — best effort: it is end of life. PHP 8.3 from Remi, MariaDB 10.11 from MariaDB's repository.

x86_64 or ARM64 · at least 2 GB RAM (4 GB or more recommended) · 15 GB free on `/var` · ports 80 and 443 free.

### Ubuntu and Debian

```bash
sudo apt-get update && sudo apt-get install -y curl
curl -fsSL https://download.worksuitecloud.com/install.sh -o install.sh
sudo bash install.sh
```

### RHEL, Rocky Linux, AlmaLinux 9

```bash
sudo dnf install -y curl
curl -fsSL https://download.worksuitecloud.com/install.sh -o install.sh
sudo bash install.sh
```

### CentOS 7

```bash
sudo yum install -y curl
curl -fsSL https://download.worksuitecloud.com/install.sh -o install.sh
sudo bash install.sh
```

### Check the installer before you run it

```bash
curl -fsSL https://download.worksuitecloud.com/install.sh.sha256 -o install.sh.sha256
sha256sum -c install.sh.sha256
```

### Useful options

- `--channel beta` — Beta releases (`lts` too, when your licence includes it).
- `--version 3.1.0` — a specific published version.
- `--db-host … --db-name … --db-user … --db-pass …` — an existing MySQL or MariaDB server.
- `--no-ssl` — TLS ends at your load balancer.
- `--non-interactive` — for automation: fail instead of asking.
- `--dry-run` — show what would happen; change nothing.
- `--repair` — re-apply the configuration to an existing installation.

Run `bash install.sh --help` for all of them. The key can also come from `WORKSUITE_LICENSE_KEY`.

### Servers without internet access

With an Enterprise licence, WorkSuite Server runs without ever reaching the Download Center:

1. Download the package and its signed manifest from your WorkSuite account, and copy them and `install.sh` to the server (it still needs your operating system's package mirrors).
2. `sudo bash install.sh --offline --package worksuite-server-….zip --manifest manifest.json --domain … --admin-email …`
3. Take `/root/worksuite-request.wsr` to a computer with internet and upload it with your licence key at [Offline activation](https://download.worksuitecloud.com/offline-activation).
4. Import the licence file (.wsl) you get back in **Admin Center › System › License**. It lasts until the licence ends or a year, whichever is first; repeat to renew.

### After installing

- Sign in at your domain and change the temporary password; turn on two-factor.
- **Admin Center › System › License** shows the licence, its edition, expiry and update eligibility.
- **Admin Center › System › Updates** installs new versions; nothing needs the command line again.
- Set up outgoing mail in Admin Center.

### Updating an existing server

Existing installations update from **Admin Center › System › Updates**. For air-gapped servers, download the edition ZIP and its signed release manifest, then upload both in **System › Updates › Manual package**.
MD;

    public const REQUIREMENTS = [
        'Ubuntu 22.04 / 24.04 LTS, Debian 12, or RHEL / Rocky / AlmaLinux 9 (CentOS 7 best effort)',
        'x86_64 or ARM64 · 2 GB RAM minimum, 4 GB or more recommended · 15 GB free on /var',
        'A domain name pointing at the server; ports 80 and 443 free',
        'A WorkSuite licence key',
    ];
}
