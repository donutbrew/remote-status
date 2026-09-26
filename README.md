# remote-status

A lightweight heartbeat monitoring system for hosts that are not directly reachable from the Internet.

`remote-status` is designed for Raspberry Pi and similar systems behind NAT or a firewall. A client periodically reports the status of one or more local services to a publicly reachable PHP endpoint. An external HTTP monitoring service, such as UptimeRobot, can then monitor the endpoint.

The system does not require inbound connections to the monitored host.

## How it works

The client runs a periodic heartbeat:

```text
Monitored host
    |
    |  HTTPS POST
    |  host=pilr
    |  pihole=ok
    |  homebridge=ok
    v
Public PHP endpoint
    |
    |  stores latest heartbeat
    v
/var/lib/remote-status/heartbeat.log
```

An external monitor checks the endpoint:

```text
UptimeRobot
    |
    |  HTTPS GET
    v
https://example.com/status/heartbeat.php?host=pilr&service=homebridge
    |
    +-- HTTP 200 = healthy
    |
    +-- HTTP 503 = unhealthy
```

This allows a single host to report an arbitrary number of services while allowing external monitoring to check either the entire host or an individual service.

## Repository structure

```text
remote-status/
├── client/
│   ├── install.sh
│   └── remote-status-heartbeat
├── server/
│   ├── install.sh
│   └── heartbeat.php
└── README.md
```

## Features

* Works with hosts behind NAT.
* Uses outbound HTTPS only.
* Supports arbitrary service names.
* Supports Docker, systemd, native commands, or any other local health check.
* Uses a shared secret for host authentication.
* Never stores the authentication key in the heartbeat log.
* Detects stale heartbeats.
* Supports whole-host and per-service HTTP health checks.
* Works with standard HTTP monitoring services.
* Uses systemd timers for periodic execution on Linux.
* Stores heartbeat history as JSON Lines.

## Requirements

### Client

The monitored host requires:

* Linux
* Bash
* `curl`
* systemd
* Network access to the server endpoint

The commands used to check services are user-defined.

For example, Docker services can be checked with:

```bash
docker inspect --format '{{.State.Health.Status}}' pihole | grep -qx healthy
```

or:

```bash
docker inspect --format '{{.State.Status}}' homebridge-homebridge-1 | grep -qx running
```

### Server

The server requires:

* Linux
* Apache or another web server capable of executing PHP
* PHP
* A writable directory for heartbeat data

The reference installation uses:

```text
/var/www/html/status/
```

for the web endpoint and:

```text
/var/lib/remote-status/
```

for heartbeat data.

## Client configuration

The client configuration is stored in:

```text
/etc/remote-status/heartbeat.conf
```

Example:

```bash
REMOTE_STATUS_URL="https://padens.us/status/heartbeat.php"
REMOTE_STATUS_HOST="pilr"
REMOTE_STATUS_KEY="REPLACE_WITH_LONG_RANDOM_SECRET"

declare -A CHECKS=(
    [pihole]='docker inspect --format "{{.State.Health.Status}}" pihole | grep -qx healthy'
    [homebridge]='docker inspect --format "{{.State.Status}}" homebridge-homebridge-1 | grep -qx running'
)
```

Each entry in `CHECKS` consists of:

```text
service name = command
```

The command must return:

```text
0     healthy
other unhealthy
```

For example:

```bash
declare -A CHECKS=(
    [pihole]='docker inspect --format "{{.State.Health.Status}}" pihole | grep -qx healthy'
    [homebridge]='docker inspect --format "{{.State.Status}}" homebridge-homebridge-1 | grep -qx running'
    [nginx]='systemctl is-active --quiet nginx'
)
```

The service names are arbitrary. They become the field names sent to the server.

## Protect the configuration

The configuration contains the host authentication key and should not be readable by unprivileged users.

```bash
sudo chown root:root /etc/remote-status/heartbeat.conf
sudo chmod 600 /etc/remote-status/heartbeat.conf
```

Do not commit the configuration file containing a real key to source control.

## Client heartbeat command

The client executable is:

```text
/usr/local/sbin/remote-status-heartbeat
```

Run it manually to test the configuration:

```bash
sudo /usr/local/sbin/remote-status-heartbeat
```

A successful heartbeat produces output similar to:

```text
Heartbeat accepted:
  pihole       ok
  homebridge   ok
```

## systemd configuration

The client can run periodically using a systemd service and timer.

### Service

Create:

```text
/etc/systemd/system/remote-status.service
```

with:

```ini
[Unit]
Description=remote-status heartbeat
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/remote-status-heartbeat
```

### Timer

Create:

```text
/etc/systemd/system/remote-status.timer
```

with:

```ini
[Unit]
Description=remote-status heartbeat timer

[Timer]
OnBootSec=30s
OnUnitActiveSec=2min
Persistent=true

[Install]
WantedBy=timers.target
```

Reload systemd:

```bash
sudo systemctl daemon-reload
```

Enable and start the timer:

```bash
sudo systemctl enable --now remote-status.timer
```

Check the timer:

```bash
systemctl status remote-status.timer
```

List the next scheduled execution:

```bash
systemctl list-timers remote-status.timer
```

Run the service manually:

```bash
sudo systemctl start remote-status.service
```

View service logs:

```bash
sudo journalctl -u remote-status.service
```

Follow the logs:

```bash
sudo journalctl -u remote-status.service -f
```

## Server configuration

The server endpoint is:

```text
/status/heartbeat.php
```

The PHP script stores heartbeat data in:

```text
/var/lib/remote-status/heartbeat.log
```

The log uses JSON Lines format. Each heartbeat is one JSON object on one line.

Example:

```json
{"received":"2026-09-26T17:42:22+00:00","reported":"2026-09-26T17:42:22+00:00","host":"pilr","pihole":"ok","homebridge":"ok"}
```

The authentication key is not written to the log.

## Host authentication

Hosts are authenticated using a host-specific shared secret.

The server configuration contains a mapping similar to:

```php
$hosts = [
    'pilr' => [
        'key' => 'REPLACE_WITH_LONG_RANDOM_SECRET',
    ],
];
```

The client sends:

```text
host
key
timestamp
```

along with the service status fields.

The server rejects requests with an unknown host or incorrect key.

## POST API

The heartbeat endpoint accepts HTTP POST requests.

Required fields:

```text
host
key
timestamp
```

All additional fields are interpreted as service status fields.

For example:

```text
host=pilr
key=...
timestamp=2026-09-26T17:42:22-04:00
pihole=ok
homebridge=ok
```

Service names must match:

```text
^[a-zA-Z0-9_-]+$
```

Service values must be either:

```text
ok
```

or:

```text
fail
```

### Successful request

The server returns:

```http
HTTP/1.1 200 OK
```

with:

```json
{
  "status": "accepted",
  "host": "pilr"
}
```

The authentication key is never included in the response.

## GET API

The GET interface is intended for external monitoring systems.

### Check the entire host

```text
https://padens.us/status/heartbeat.php?host=pilr
```

This checks:

1. A heartbeat exists.
2. The heartbeat is recent.
3. Every reported service has status `ok`.

A healthy host returns:

```http
HTTP/1.1 200 OK
```

Example:

```json
{
  "status": "healthy",
  "host": "pilr",
  "age_seconds": 42,
  "last_received": "2026-09-26T17:42:22+00:00",
  "services": {
    "pihole": "ok",
    "homebridge": "ok"
  }
}
```

### Check a specific service

Use the `service` query parameter:

```text
https://padens.us/status/heartbeat.php?host=pilr&service=homebridge
```

A healthy service returns:

```http
HTTP/1.1 200 OK
```

Example:

```json
{
  "status": "healthy",
  "host": "pilr",
  "service": "homebridge",
  "service_status": "ok",
  "age_seconds": 42,
  "last_received": "2026-09-26T17:42:22+00:00"
}
```

If Homebridge reports `fail`, the endpoint returns:

```http
HTTP/1.1 503 Service Unavailable
```

Example:

```json
{
  "status": "unhealthy",
  "host": "pilr",
  "service": "homebridge",
  "service_status": "fail",
  "age_seconds": 42,
  "last_received": "2026-09-26T17:42:22+00:00"
}
```

This distinction allows an external monitoring service to monitor individual services.

## HTTP status codes

| Condition                   |    HTTP status |
| --------------------------- | -------------: |
| Healthy                     |          `200` |
| Heartbeat accepted          |          `200` |
| Missing required POST field |          `400` |
| Invalid host                | `400` or `401` |
| Invalid service name        |          `400` |
| Invalid service status      |          `400` |
| Service not reported        |          `503` |
| No heartbeat                |          `503` |
| Heartbeat stale             |          `503` |
| Service unhealthy           |          `503` |
| Log write failure           |          `500` |
| Unsupported HTTP method     |          `405` |

The distinction between `200` and `503` is intentional. Standard HTTP monitoring services can use the response status directly without understanding the JSON response body.

## Heartbeat expiration

The server considers a heartbeat stale after:

```text
360 seconds
```

or six minutes.

The client currently sends a heartbeat every two minutes.

This provides multiple opportunities for the client to report before the server considers the heartbeat stale.

## UptimeRobot

Create an HTTP(s) monitor in UptimeRobot.

### Monitor the entire host

Use:

```text
https://padens.us/status/heartbeat.php?host=pilr
```

The monitor should consider HTTP `200` successful.

### Monitor an individual service

For Pi-hole:

```text
https://padens.us/status/heartbeat.php?host=pilr&service=pihole
```

For Homebridge:

```text
https://padens.us/status/heartbeat.php?host=pilr&service=homebridge
```

This allows UptimeRobot to report the individual service as down when that service returns HTTP `503`.

For example:

```text
UptimeRobot monitor
    |
    +-- PiLR / Pi-hole
    |     GET ?host=pilr&service=pihole
    |
    +-- PiLR / Homebridge
          GET ?host=pilr&service=homebridge
```

A separate monitor for each service also makes the alert name identify the affected service.

## Testing

### Test the client

Run:

```bash
sudo systemctl start remote-status.service
```

Then inspect the logs:

```bash
sudo journalctl -u remote-status.service -n 20
```

### Test the whole-host endpoint

```bash
curl -i \
  "https://padens.us/status/heartbeat.php?host=pilr"
```

Expected healthy response:

```text
HTTP/1.1 200 OK
```

### Test an individual service

```bash
curl -i \
  "https://padens.us/status/heartbeat.php?host=pilr&service=homebridge"
```

Expected healthy response:

```text
HTTP/1.1 200 OK
```

### Test an unhealthy service

Stop the service being monitored, then send a heartbeat.

For example:

```bash
docker stop homebridge-homebridge-1
```

Send a heartbeat:

```bash
sudo systemctl start remote-status.service
```

Then:

```bash
curl -i \
  "https://padens.us/status/heartbeat.php?host=pilr&service=homebridge"
```

The expected response is:

```text
HTTP/1.1 503 Service Unavailable
```

Restore the service:

```bash
docker start homebridge-homebridge-1
```

Send another heartbeat:

```bash
sudo systemctl start remote-status.service
```

The endpoint should return HTTP `200` again.

## Troubleshooting

### The heartbeat command fails

Run it directly:

```bash
sudo /usr/local/sbin/remote-status-heartbeat
```

Then inspect the systemd journal:

```bash
sudo journalctl -u remote-status.service -n 50
```

### The server returns `401`

Check:

* `REMOTE_STATUS_HOST`
* `REMOTE_STATUS_KEY`
* The corresponding host entry on the server

Do not put the real key into command output, issue reports, or source control.

### The server returns `503`

Check the endpoint:

```bash
curl -i \
  "https://padens.us/status/heartbeat.php?host=pilr"
```

If the response says `stale`, verify that the systemd timer is running:

```bash
systemctl status remote-status.timer
```

Check recent executions:

```bash
sudo journalctl -u remote-status.service -n 50
```

### A service is reported as unhealthy

Run its configured command manually.

For example:

```bash
docker inspect --format "{{.State.Health.Status}}" pihole
```

or:

```bash
docker inspect --format "{{.State.Status}}" homebridge-homebridge-1
```

Then check the service configuration in:

```text
/etc/remote-status/heartbeat.conf
```

### The timer is not running

Check:

```bash
systemctl status remote-status.timer
```

Reload systemd if the unit files were recently changed:

```bash
sudo systemctl daemon-reload
```

Then:

```bash
sudo systemctl enable --now remote-status.timer
```

## Security considerations

The heartbeat endpoint is intentionally public because an external monitoring service must be able to access it.

The endpoint does not expose the authentication key.

The client key should be treated as a password:

* Use a long random value.
* Store it in `/etc/remote-status/heartbeat.conf`.
* Restrict permissions on that file.
* Do not commit it to Git.
* Do not include it in diagnostic output.

The server should use HTTPS.

The heartbeat log should also be protected from unnecessary access:

```bash
sudo chown www-data:www-data /var/lib/remote-status/heartbeat.log
sudo chmod 640 /var/lib/remote-status/heartbeat.log
```

## Design notes

### Why use HTTP status codes?

The monitoring client does not need to understand the application-specific JSON response.

The API uses the conventional HTTP distinction:

```text
200 = success
503 = service unavailable
```

This makes the endpoint compatible with simple HTTP monitoring systems.

The JSON response remains useful for humans and troubleshooting.

### Why use a heartbeat instead of inbound monitoring?

The monitored host may be behind:

* NAT
* A residential router
* A firewall
* CGNAT
* Dynamic addressing

The client initiates the connection, so no inbound connection to the monitored host is required.

### Why use arbitrary service names?

The monitoring system is intentionally independent of the services being monitored.

The same client can report:

```text
pihole=ok
homebridge=ok
nginx=ok
ssh=ok
disk=ok
backup=ok
```

without changing the server implementation.

## Future extensions

Potential future additions include:

* A human-readable status page.
* A dashboard showing service history.
* Multiple hosts.
* Service-specific heartbeat intervals.
* Disk space and temperature metrics.
* Authentication keys stored outside the PHP source tree.
* Log rotation.
* A JSON API for historical status.
* Additional notification integrations.

## License

GPL3 License terms
