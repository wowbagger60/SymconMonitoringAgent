# Symcon Monitoring Agent

Privates IP-Symcon-Modul von Cassanis Automation. Das Modul sendet in einem
konfigurierbaren Intervall einen signierten Heartbeat an den zentralen
Monitoring-Endpunkt.

Das Modul legt keine Variablen an. Betriebszustand und Übertragungsfehler werden
über den Instanzstatus, das Debugfenster und das normale Symcon-Log gemeldet.

## Installation

Das Repository über die IP-Symcon-Modulverwaltung installieren und danach eine
Instanz **Heartbeat Agent** anlegen.

## Konfiguration

- **Aktiv**: Schaltet den automatischen Versand ein.
- **System-ID**: Die auf dem Monitoring-Server registrierte technische ID.
- **Heartbeat-Endpunkt**: HTTPS-Adresse der zentralen `heartbeat.php`.
- **Gemeinsames Secret**: Das zum System gehörende 64-stellige Hex-Secret.
- **Intervall**: Sendeintervall in Sekunden, standardmässig 60 Sekunden.

Mit **Heartbeat jetzt senden** kann die Verbindung auch bei deaktiviertem
Automatikbetrieb geprüft werden. Ein erfolgreicher Aufruf erwartet HTTP 204.

## Verhalten

Nach dem Aktivieren beziehungsweise nach einer Konfigurationsänderung wird der
erste Heartbeat nach einer Sekunde gesendet. Nach einem Kernelstart wartet das
Modul fünf Sekunden. Danach gilt das konfigurierte Intervall.

Die Sequenznummer wird vor dem Versand in einem Modul-Buffer gespeichert und
auch bei einem fehlgeschlagenen Versuch nicht wiederverwendet.

## Datenschutz und Sicherheit

Das Secret wird ausschliesslich zur HMAC-Signierung verwendet und weder in die
Debugausgabe noch in Fehlermeldungen aufgenommen. TLS-Zertifikat und Hostname
des Endpunkts werden geprüft.

## Lizenz

Privates Modul. Alle Rechte vorbehalten.
