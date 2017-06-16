# VirtualDevice

Die Bibliothek VirtualDevice unterstütz die virtualisierung von Hardware-Geräten in den Bereichen Licht, Steckdose und Rollo

Ziel ist es eine Kapselung von den echten Hardware-Instanzen im IP Symcon zu schaffen und damit
- bei Hardware-Wechsel die Änderungen an zentraler Steller vorzunehmen
- die Funktionalität abzugleichen und herstellerunabhängig gleichartig zur Verfügung zu stellen.

Das sorgt dafür das alle Geräte der selben Klasse die identische Funktion und die identische Verwendung haben,
egal von welchem Hersteller und System die Hardware ist.

Weiterhin wird die Reaktionsgeschwindigkeit der Schaltflächen beschleunigt (es wird sofort reagiert) ohne eine echte 
Überprüfung des Zustands zu verlieren (wie es beim "Status simulieren" der Fall wäre). Hierzu wird der Zustand sofort geändert,
aber die echte Durchführung durch einen Timer überprüft, wiederholt (2x) und im Falle das der Aktor nicht reagiert 
ein Fehler gemeldet.

Weitere Beschreibungen in den einzelnen Modulen.

Prinzip der Verwendung:
Die "realen" Aktoren werden angelegt an einem zentralen Ort. Hier ist der Aufbau relativ egal, ich empfehle aber eine Sortierung
die der realen Physik entspricht, z.B.
-IP-Symcon
--Hardware
---Unterverteilung HWR
----Deckenlicht Wohnzimmer <- z.B. Eltako-Aktor
usw.....

Diese Aktoren werden in keinem Script, keinem Event usw. verwendet! Sie dienen ausschließlich als Treiber/Hardware-Schnittstelle.
Diese Instanzen werden dann von den virtuellen Instanzen gewrappt. Die virtuellen Instanzen werden dann in Events, Scripten 
oder zur Verknüpfung und in der Visualisierung verwendet. 
Diese empfiehlt es sich nach dem Einsatz zu sortieren, z.B. so:


* *01-IP-Symcon
 * *02--Hardware <- "echte" Instanzen mit der Hardware
  * *03---Unterverteilung HWR
   * *04----Deckenlicht Wohnzimmer <- z.B. Eltako-Aktor
* ............
 * *13-- Geräte <- virtuelle Instanzen
  * *14--- Erdgeschoss
   * *15---- Wohnzimmer
    * *16----- Deckenlicht <- dies ist dann die virtuelle Instanz mit Link auf *04

Wenn jetzt die Hardware-Instanz *04 kaputt geht und durch einen neuen Aktor ausgetauscht der die IPS-ID *05 bekommt,
 wird nur in der virtuellen Instanz *16 der Link auf *04 gegen *05 ausgetauscht und alle anderen Scripte und Events können so weiter 
 bestehen wie heute.
 
 Durch die gleichartigen virtuellen Instanzen sehen auch alle z.B. Licht-Aktoren identisch aus, egal von welchem System und Hersteller.
 Alle haben die selben Profile und Funktionen und können so einfacher in der Visualisierung also z.B. im Webfront verwendet werden.
 
 Verwendung der virtuellen Instanzen:
 - Kompatibiliätsscripte und/oder Variablen anlegen (siehe Readme der Instanz)
 - Neue Instanz anlegen vom gewünschten Typ
 - Ziel-Instanz wählen (die originale Instanz)
 - ggf. Instanz-abhängige Konfiguration vornehmen (siehe Readme der Instanz)
 - ggf. existierende Verweise in Scripten, Events oder Links auf die virtuelle Instanz abhändern
 - "Hardare-Instanz" vergessen und nur noch mit der virtuellen Instanz weiter arbeiten
