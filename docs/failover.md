# Failover & cold-standby DB (recommendation #16)

This document is the runbook for surviving a Droplet death. Single-region
production today; ~60s DNS-flip to a warm standby is the target. Costs
about $30/mo for the standby DB + $0 for DNS.

## Architecture today

* One DigitalOcean Droplet at `143.244.144.7` (NYC).
* MySQL 8 + Apache + PHP-FPM all on the same box.
* MySQL data lives at `/var/lib/mysql/smappen` — ~3GB after the
  50-state Census load.

## Architecture goal

* **Primary**: existing Droplet, NYC.
* **Standby**: same-size Droplet in SFO3 (or AMS3 for EU resilience).
* **Replication**: MySQL native async replica streaming binlog from
  primary → standby. ~200ms latency, daily verification cron.
* **DNS**: Cloudflare or DO Networking, A-record TTL 60s. Flip via a
  one-line CLI command from a local laptop.
* **Code deployment**: `scripts/deploy.sh` already runs on the primary.
  Add a `--mirror=143.244.144.7,STANDBY_IP` switch so every push goes
  to both.

## One-shot bootstrap script

```bash
# On STANDBY box, after DO Droplet is spun up:
ssh root@STANDBY_IP bash -c "$(cat <<'SH'
set -e
apt-get update && apt-get install -y mysql-server php8.4-fpm apache2

# Copy primary's mysql config + binlog GTID setup
scp root@143.244.144.7:/etc/mysql/mysql.conf.d/mysqld.cnf /etc/mysql/mysql.conf.d/
systemctl restart mysql

# Take a primary backup
ssh root@143.244.144.7 "mysqldump --single-transaction --routines --triggers \
  --master-data=2 --gtid-mode=on -u smappen -p$DB_PASS smappen" \
  | mysql -u smappen -p$DB_PASS smappen

# Configure replication
mysql -e "CHANGE REPLICATION SOURCE TO
  SOURCE_HOST='143.244.144.7',
  SOURCE_USER='repl',
  SOURCE_PASSWORD='REPL_PASSWORD',
  SOURCE_AUTO_POSITION=1;
START REPLICA;"

# Mirror app code
rsync -av root@143.244.144.7:/var/www/smappen/ /var/www/smappen/
SH
)"
```

## Daily replica health check (`scripts/check-replica.sh`)

```bash
#!/usr/bin/env bash
set -e
STATUS=$(ssh root@STANDBY_IP "mysql -e 'SHOW REPLICA STATUS\G'")
LAG=$(echo "$STATUS" | grep 'Seconds_Behind_Source' | awk '{print $2}')
ERR=$(echo "$STATUS" | grep 'Last_Error' | head -1 | cut -d: -f2-)
if [[ -n "$ERR" && "$ERR" != " " ]]; then
  echo "REPLICA ERROR: $ERR" | mail -s "Smappen replica error" $ALERT_EMAIL
fi
if [[ "$LAG" -gt 60 ]]; then
  echo "REPLICA LAG ${LAG}s" | mail -s "Smappen replica lagging" $ALERT_EMAIL
fi
```

Add to root crontab: `0 * * * * /var/www/smappen/scripts/check-replica.sh`

## Failover procedure

When the primary is dead:

```bash
# 1. Confirm primary unreachable
ping -c 3 143.244.144.7   # times out

# 2. Promote standby
ssh root@STANDBY_IP "mysql -e 'STOP REPLICA; RESET REPLICA ALL;'"

# 3. Flip Cloudflare DNS A record (or DigitalOcean DNS)
doctl compute domain records update mygreendock.com \
  --record-id RECORD_ID --record-data STANDBY_IP

# 4. Wait 60s for TTL, then verify
curl https://smappen.mygreendock.com/api/health
```

Total time-to-recovery: ~3 minutes with prepared scripts.

## What's NOT covered

* Cross-region object storage for uploaded images (`uploads/`).
* DNS provider failure (Cloudflare-only is a single point of failure;
  add a secondary DNS like Route53).
* Stripe / SES / ORS / Census API outages — those are external and the
  app degrades gracefully (cached results stay served).

## Open questions before v1

* Pick standby region (SFO3 vs AMS3 vs SGP1).
* Decide read-from-standby capability for analytics queries — easy with
  a second DB connection, doubles available read throughput.
* Document the upgrade path to managed DB (DigitalOcean Managed MySQL
  has built-in standby + auto-failover for $50/mo on top of the
  Droplet; might be cheaper net than DIY).
