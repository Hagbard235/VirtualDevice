# BewegungsmelderProxy

Steuert ein Licht über eine ODER-Verknüpfung mehrerer Bewegungs-/Präsenzmelder. Im Modus **Auto (Lux)** wird die konfigurierte Helligkeit berücksichtigt; **Auto (Tag+Nacht)** reagiert unabhängig davon. **Dauer Ein** und **Dauer Aus** sind explizite Betriebsarten.

## Version 1.1.0

Die Version behebt Fälle, in denen Licht ohne weiteren Ausschaltversuch an blieb:

- Zustandsübergänge sind pro Instanz serialisiert. Geräteaufrufe erfolgen separat, damit wartende Aktoren die Zustandsverarbeitung nicht unter derselben Sperre blockieren.
- Ein bereits eingeplanter alter Timer kann einen inzwischen verlängerten Nachlauf nicht beenden. Ein alter Geräteaufruf kann keinen neueren Schaltauftrag quittieren oder löschen.
- Beim Timerablauf und beim Übernehmen der Konfiguration wird Bewegung direkt aus den Sensorvariablen neu ermittelt.
- Jeder Schaltauftrag wird nach fünf Sekunden geprüft und bei fehlender Bestätigung höchstens zweimal wiederholt: insgesamt drei Versuche. Danach zeigt die Variable **Schaltfehler** den Fehler an. Eine spätere erfolgreiche Bestätigung/ein erfolgreicher neuer Auftrag löscht den Fehler.
- Automatische Ausschaltwiederholungen berücksichtigen neue Präsenz. Explizites manuelles AUS bleibt möglich, auch wenn ein Sensor noch Präsenz meldet.
- Ein bereits eingeschaltetes Licht erhält beim Wechsel zurück in Automatik einen Nachlauf, auch wenn es inzwischen zu hell für ein neues automatisches Einschalten ist.
- Das erzeugte **An**-Skript / `BWMProxy_SetLight()` nutzt dieselbe Bedienlogik wie die Status-Aktion. Direkt am Aktor erkanntes EIN bekommt im Automatikmodus ebenfalls einen Nachlauf.
- Die Mindestdauer beträgt eine Sekunde. Ein bisher gespeicherter Wert 0 wird zur Laufzeit auf eine Sekunde begrenzt.

### Bedienverhalten

Eine explizite Ein-/Aus-Aktion, die dem aktuellen Dauer-Modus widerspricht, wechselt in den gespeicherten Automatikmodus zurück (ersatzweise Auto/Lux). So erzeugt manuelles EIN in „Dauer Aus“ kein unbegrenzt eingeschaltetes Licht. Für bewusst dauerhaftes Licht **Dauer Ein** wählen.

Echte Dauerpräsenz verlängert einen laufenden Nachlauf weiterhin unabhängig von der Helligkeit. Ein auf `true` hängen gebliebener physischer Sensor kann daher weiterhin das Licht halten. Es gibt absichtlich keine pauschale maximale Anwesenheitsdauer.

Die Gerätebefehle werden über einen Timer mit 100 ms Intervall abgearbeitet. Der angezeigte Lichtstatus ist zunächst der Wunschzustand und wird anschließend durch Rückmeldung bzw. Nachprüfung abgeglichen. Eine Symcon-Statusvariable ist nur so zuverlässig wie die Rückmeldung des jeweiligen Aktors.

## Update

Bibliothek in der Symcon-Modulverwaltung aktualisieren. Die Version ergänzt die Variable `SwitchError` und den internen `DispatchTimer`; bestehende Sensor-/Lichtzuordnungen bleiben erhalten. Beim Neuladen wird für ein bereits eingeschaltetes Licht im Automatikmodus ein voller Nachlauf gestartet.

Die GitHub-Veröffentlichung installiert das Update nicht automatisch auf einem Symcon-Server.

## Tests

Ohne Symcon oder Hardware ausführbar:

```sh
php -n tests/BewegungsmelderProxyTest.php
```

Die Tests verwenden den echten Modulcode mit simulierten Sensoren, Geräteantworten, Zeit und Sperren. Sie prüfen auch einen neuen Bewegungsaufruf während eines noch laufenden Ausschaltaufrufs. Sie ersetzen keinen Integrationstest mit Funkaktoren und dem Symcon-Scheduler.
