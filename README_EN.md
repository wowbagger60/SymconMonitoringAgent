# Symcon Monitoring Agent

Private IP-Symcon module by Cassanis Automation. It periodically sends a signed
heartbeat to the central monitoring endpoint.

The module creates no variables. Its instance status, the debug log and the
regular Symcon log provide operational diagnostics.

## Configuration

- **Active** enables automatic transmission.
- **System ID** is the technical ID registered by the monitoring server.
- **Heartbeat endpoint** is the HTTPS URL of `heartbeat.php`.
- **Shared secret** is the 64-character hexadecimal secret assigned to the system.
- **Interval** defaults to 60 seconds.

Use **Send heartbeat now** to test transmission even when automatic operation
is disabled. A successful request must return HTTP 204.

## Licence

Private module. All rights reserved.
