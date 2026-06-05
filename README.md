# client_installer

REDAXO-Addon als Unterseite von `install`, um AddOns aus dem KLXM Installer Proxy zu beziehen.

## Funktionen

- Paketliste aus dem Proxy anzeigen
- verfügbare Versionen je Paket abrufen
- ZIP aus dem Proxy laden und in den AddOn-Ordner entpacken
- direkter Installationslink über den REDAXO-Installer-API-Call
- optionaler Update-Hinweis als blinkendes Badge im Installer-Tab

## Einrichtung

1. AddOn installieren und aktivieren.
2. Unter `Installer > Client Installer > Einstellungen` konfigurieren:
   - Proxy-Basis-URL
   - API-Token
   - Timeout
3. Unter `Installer > Client Installer > Pakete` Pakete laden und installieren.

## Hinweis

Für den Betrieb wird ein laufender KLXM Installer Proxy benötigt.
