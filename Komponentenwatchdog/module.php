<?php

/**
 * Komponentenwatchdog
 *
 * Fragt fuer eine gepflegte Liste von Komponenten, ob sie noch leben - jede mit
 * der Methode, die ihr System erlaubt - und sagt Bescheid, wenn sich die
 * Antwort aendert. Anlass: Ein Tuerkontakt mit leerer Batterie hat zwei Wochen
 * lang still eine Funktion abgeschaltet, und niemand hat es bemerkt.
 *
 * Grundsaetze (Konzept 21_Konzept_Komponentenwatchdog.md):
 *
 * - Wechsel statt Zustand. Gemeldet wird der Uebergang von lebend nach
 *   gestoert und zurueck, jeweils einmal. Was schon beim Einrichten gestoert
 *   ist, ist Altbestand und erscheint in der Uebersicht, nicht als Alarm.
 * - Eine Pruefregel je System. Homematic meldet Erreichbarkeit selbst, Zigbee
 *   liefert einen Zeitstempel - eine gemeinsame Regel waere fuer beide falsch.
 * - Bruecke vor Geraet. Faellt die Bruecke aus, eine Meldung ueber sie statt
 *   einer Flut ueber alle Geraete dahinter.
 * - Zustellung ueber die Meldungszentrale, vertraulich an eine Person.
 * - Keine Suche im Objektbaum waehrend des Betriebs.
 */
class Komponentenwatchdog extends IPSModule {

    const Z_UNBEKANNT  = "unbekannt";
    const Z_OK         = "ok";
    const Z_GESTOERT   = "gestoert";
    const Z_ALTBESTAND = "altbestand";

    const B_OK        = "ok";
    const B_GESTOERT  = "gestoert";
    const B_UNBEKANNT = "unbekannt";
    // Die Quelle der Wahrheit ist gerade nicht abfragbar (z. B. CCU). Dann
    // bleibt der Zustand, wie er ist - statt fuer jedes Geraet Wartung zu melden.
    const B_AUSSETZEN = "aussetzen";

    /** Servicemeldungen je CCU-Socket, einmal pro Pruefzyklus gelesen. */
    private $ccuCache = [];

    const REGELN   = ["instanzstatus", "homematic", "zigbee2mqtt", "wertalter"];
    const GEWICHTE = ["sicherheit", "funktion", "allgemein"];

    public function Create() {
        parent::Create();

        $this->RegisterPropertyString("Komponenten", "[]");

        $this->RegisterPropertyInteger("MeldungszentraleID", 0);
        $this->RegisterPropertyString("Empfaenger", "andre");
        $this->RegisterPropertyString("Quelle", "watchdog");
        // Abschaltbar fuer Tests und Einrichtung: Zustaende werden weiter
        // gefuehrt, Meldungen landen nur im Protokoll.
        $this->RegisterPropertyBoolean("MeldenAktiv", true);

        $this->RegisterPropertyInteger("PruefintervallMinuten", 5);
        $this->RegisterPropertyInteger("Entprellung", 2);
        $this->RegisterPropertyInteger("KarenzMinuten", 15);
        $this->RegisterPropertyInteger("SchwelleStandardMinuten", 1500);
        $this->RegisterPropertyInteger("BatterieProzent", 15);
        $this->RegisterPropertyInteger("ConfigPendingStunden", 24);
        $this->RegisterPropertyInteger("SammelausfallProzent", 50);
        $this->RegisterPropertyString("Tagesuebersicht", "18:00");

        // Flaechendeckend je System. Kein Geraet im Haus ist ohne Funktion -
        // das Gewicht entscheidet, wie schnell gemeldet wird, nicht ob. Was
        // nicht in der Liste steht, laeuft als "allgemein" mit. Die Liste
        // hebt einzelne Geraete an oder stellt sie ruhend.
        $this->RegisterPropertyBoolean("SystemHomematic", true);
        $this->RegisterPropertyString("HomematicBruecke", "hm_socket");
        $this->RegisterPropertyBoolean("SystemZigbee", true);
        $this->RegisterPropertyInteger("Zigbee2MQTTKonfigurator", 0);
        $this->RegisterPropertyString("ZigbeeBruecke", "z2m_client");
        $this->RegisterPropertyBoolean("SystemInstanzen", true);
        $this->RegisterPropertyInteger("AbdeckungNeuErmittelnMinuten", 60);

        $this->RegisterAttributeString("Zustaende", "{}");
        $this->RegisterAttributeString("Tagesliste", "[]");
        $this->RegisterAttributeString("Protokoll", "[]");
        // Seit wann eine voruebergehende CCU-Servicemeldung (CONFIG_PENDING)
        // ansteht - sie zaehlt erst nach der eingestellten Dauer.
        $this->RegisterAttributeString("CCUErstGesehen", "{}");

        $this->RegisterVariableInteger("LetztePruefung", "Letzte Pruefung", "~UnixTimestamp", 10);
        $this->RegisterVariableInteger("AnzahlGestoert", "Gestoert", "", 20);
        $this->RegisterVariableInteger("AnzahlWartung", "Wartung", "", 30);
        $this->RegisterVariableString("Uebersicht", "Uebersicht", "~HTMLBox", 40);

        $this->RegisterTimer("Pruefung", 0, 'KWD_Pruefen($_IPS[\'TARGET\']);');
        $this->RegisterTimer("Tagesuebersicht", 0, 'KWD_TagesuebersichtSenden($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $fehler = $this->KonfigurationPruefen();
        if ($fehler !== "") {
            $this->SendDebug("Konfiguration", $fehler, 0);
            $this->SetStatus(201);
        } else {
            $this->SetStatus(102);
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->TimerStellen();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message == IPS_KERNELSTARTED) $this->TimerStellen();
    }

    // ==================================================================
    // Oeffentliche Schnittstelle
    // ==================================================================

    /** Ein Pruefzyklus ueber alle Komponenten. Laeuft auch per Timer. */
    public function Pruefen() {
        $lock = "KWD_" . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 5000)) return;
        try {
            $this->PruefenIntern();
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /** Sammelmeldung des Tages. Laeuft per Timer, laesst sich aber auch von Hand ausloesen. */
    public function TagesuebersichtSenden() {
        $this->TimerTagesuebersicht();
        $liste = json_decode($this->ReadAttributeString("Tagesliste"), true);
        if (!is_array($liste) || count($liste) === 0) return;

        // Was am selben Tag ausfiel und zurueckkam, hebt sich auf. Eine Lampe
        // am Wandschalter ist abends aus und morgens wieder da - das ist kein
        // Befund. Gemeldet wird, was zum Zeitpunkt der Uebersicht noch ansteht.
        $gegenstueck = ["gestoert" => "entwarnung", "wartung" => "wartung_behoben"];
        $offen = [];
        foreach ($liste as $i => $e) {
            $art = $e["art"];
            if (isset($gegenstueck[$art])) {
                $offen[$e["key"] . "|" . $gegenstueck[$art]][] = $i;
            } elseif (in_array($art, $gegenstueck, true) && !empty($offen[$e["key"] . "|" . $art])) {
                $vorher = array_pop($offen[$e["key"] . "|" . $art]);
                unset($liste[$vorher], $liste[$i]);
            }
        }
        $liste = array_values($liste);
        if (count($liste) === 0) { $this->WriteAttributeString("Tagesliste", "[]"); return; }

        $gruppen = [];
        foreach ($liste as $e) $gruppen[$e["art"]][] = $e;

        $teile = [];
        $namen = function (array $es, bool $mitGrund) {
            $out = [];
            foreach ($es as $e) $out[] = $mitGrund && $e["grund"] !== "" ? $e["name"] . " (" . $e["grund"] . ")" : $e["name"];
            return implode(", ", array_unique($out));
        };
        if (!empty($gruppen["gestoert"]))        $teile[] = "Ausgefallen: " . $namen($gruppen["gestoert"], false);
        if (!empty($gruppen["entwarnung"]))      $teile[] = "Wieder da: " . $namen($gruppen["entwarnung"], false);
        if (!empty($gruppen["wartung"]))         $teile[] = "Wartung: " . $namen($gruppen["wartung"], true);
        if (!empty($gruppen["wartung_behoben"])) $teile[] = "Wartung erledigt: " . $namen($gruppen["wartung_behoben"], false);
        if (!empty($gruppen["altbestand"])) {
            $n = count($gruppen["altbestand"]);
            $teile[] = $n <= 4
                ? "Bei Aufnahme schon gestört: " . $namen($gruppen["altbestand"], false)
                : "Bei Aufnahme schon gestört: " . $n . " Komponenten (siehe Übersicht)";
        }

        $text = implode(". ", $teile) . ".";
        // Mit flaechendeckender Abdeckung wird die Liste schnell laenger als
        // eine Push fasst (256 Bytes). Dann Zahlen statt Namen - die Namen
        // stehen in der Geraeteuebersicht.
        if (strlen($text) > 230) {
            $zahlen = [];
            foreach (["gestoert" => "ausgefallen", "entwarnung" => "wieder da", "wartung" => "Wartung",
                      "wartung_behoben" => "Wartung erledigt", "altbestand" => "schon bei Aufnahme gestört"] as $art => $wort) {
                if (!empty($gruppen[$art])) $zahlen[] = count($gruppen[$art]) . " " . $wort;
            }
            $text = implode(", ", $zahlen) . ". Details in der Geräteübersicht.";
        }
        $this->Senden("Watchdog: Tagesübersicht", $text, "normal", "tag-" . date("Y-m-d-H-i"));
        $this->WriteAttributeString("Tagesliste", "[]");
    }

    /** Zustaende aller Komponenten als JSON - fuer Diagnose und Tests. */
    public function Status(): string {
        return json_encode([
            "zustaende"  => json_decode($this->ReadAttributeString("Zustaende"), true),
            "tagesliste" => json_decode($this->ReadAttributeString("Tagesliste"), true),
            "protokoll"  => json_decode($this->ReadAttributeString("Protokoll"), true)
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Eine Komponente neu beginnen lassen, als waere sie gerade aufgenommen.
     * Fuer den Fall, dass ein Altbestand repariert und bewusst neu bewertet
     * werden soll. Leerer Schluessel setzt alle zurueck.
     */
    public function Zuruecksetzen(string $Key) {
        $z = json_decode($this->ReadAttributeString("Zustaende"), true);
        if (!is_array($z)) $z = [];
        if ($Key === "") $z = []; else unset($z[$Key]);
        $this->WriteAttributeString("Zustaende", json_encode($z));

        // Was ueber die Komponente gesammelt wurde, gilt ebenfalls nicht mehr.
        $t = json_decode($this->ReadAttributeString("Tagesliste"), true);
        if (!is_array($t)) $t = [];
        $t = $Key === "" ? [] : array_values(array_filter($t, function ($e) use ($Key) { return ($e["key"] ?? "") !== $Key; }));
        $this->WriteAttributeString("Tagesliste", json_encode($t, JSON_UNESCAPED_UNICODE));
    }

    // ==================================================================
    // Pruefzyklus
    // ==================================================================

    private function PruefenIntern() {
        $jetzt = time();
        $liste = $this->KomponentenListe();
        $liste = $liste + $this->AutomatischeKomponenten($liste);
        $zust = json_decode($this->ReadAttributeString("Zustaende"), true);
        if (!is_array($zust)) $zust = [];

        // Wer ist Bruecke fuer wen?
        $abhaengige = [];
        foreach ($liste as $key => $k) {
            if ($k["Bruecke"] !== "" && isset($liste[$k["Bruecke"]])) $abhaengige[$k["Bruecke"]][] = $key;
        }

        // 1. Befunde
        $this->ccuCache = [];
        $befund = [];
        foreach ($liste as $key => $k) {
            if ($this->Ueberwacht($k)) $befund[$key] = $this->Befund($k);
        }
        $this->CCUErstGesehenAufraeumen();

        // 2. Sammelausfall: Verstummt die Mehrheit der Geraete hinter einer
        //    Bruecke gleichzeitig, ist die Bruecke das Problem - auch wenn sie
        //    selbst gruen meldet. Beispiel: Zigbee2MQTT abgestuerzt, der
        //    MQTT-Client von Symcon bleibt verbunden. Gezaehlt werden nur
        //    Geraete, die schon einmal gelebt haben, sonst machte Altbestand
        //    jede Bruecke dauerhaft verdaechtig.
        $prozent = max(1, $this->ReadPropertyInteger("SammelausfallProzent"));
        foreach ($abhaengige as $b => $keys) {
            if (!isset($befund[$b])) continue;
            // Zaehlbasis sind nur Geraete, die bis eben lebten. Schon einzeln
            // gemeldete oder Altbestand zaehlen nicht - sonst loeste jede
            // Einzelmeldung gleich den naechsten Sammelausfall aus.
            $gesamt = 0; $still = 0; $lebend = 0; $bekannt = 0;
            foreach ($keys as $key) {
                if (!isset($befund[$key])) continue;
                $zz = $zust[$key]["zustand"] ?? self::Z_UNBEKANNT;
                if ($zz === self::Z_OK || $zz === self::Z_GESTOERT) {
                    $bekannt++;
                    if ($befund[$key]["status"] === self::B_OK) $lebend++;
                }
                if ($zz !== self::Z_OK) continue;
                $gesamt++;
                if ($befund[$key]["status"] === self::B_GESTOERT) $still++;
            }

            $zb = $zust[$b] ?? [];
            $zbZustand = $zb["zustand"] ?? self::Z_UNBEKANNT;

            // Wegen Sammelausfall gestoert: wieder da, sobald IRGENDETWAS
            // durchkommt. Wuerde sie erst bei der Mehrheit wieder freigegeben,
            // blieben wirklich ausgefallene Geraete dahinter fuer immer
            // eingefroren und wuerden nie einzeln gemeldet.
            if ($zbZustand === self::Z_GESTOERT && !empty($zb["sammelausfall"])) {
                if ($lebend === 0 && $bekannt > 0) {
                    $befund[$b]["status"] = self::B_GESTOERT;
                    $befund[$b]["grund"] = $zb["grund"];
                }
                $befund[$b]["sammelausfall"] = true;
                continue;
            }

            // Erkannt wird ein Sammelausfall nur an einer laufenden Bruecke.
            // Eine selbst gestoerte Bruecke kehrt ueber ihr eigenes Signal zurueck.
            if ($zbZustand !== self::Z_OK || $befund[$b]["status"] !== self::B_OK) continue;
            if ($gesamt >= 3 && $still * 100 >= $gesamt * $prozent) {
                $befund[$b]["status"] = self::B_GESTOERT;
                $befund[$b]["grund"] = "Sammelausfall: " . $still . " von " . $gesamt . " Komponenten dahinter still";
                $befund[$b]["sammelausfall"] = true;
            }
        }

        // 3. Uebergaenge - Bruecken vor den Geraeten dahinter, damit ein
        //    Geraet schon den neuen Zustand seiner Bruecke sieht.
        $reihenfolge = array_keys($befund);
        usort($reihenfolge, function ($a, $b) use ($liste) {
            return ($liste[$a]["Bruecke"] === "" ? 0 : 1) <=> ($liste[$b]["Bruecke"] === "" ? 0 : 1);
        });

        $karenz = max(0, $this->ReadPropertyInteger("KarenzMinuten")) * 60;
        foreach ($reihenfolge as $key) {
            $k = $liste[$key];
            $z = $zust[$key] ?? ["zustand" => self::Z_UNBEKANNT, "seit" => $jetzt, "zaehler" => 0, "grund" => "",
                                 "okSeit" => 0, "meldungID" => "", "wartung" => null, "wartungSeit" => 0, "wartungMeldungID" => ""];
            $z["name"] = $k["Name"];
            $z["regel"] = $k["Regel"];
            $z["gewicht"] = $k["Gewicht"];
            $z["geprueft"] = $jetzt;
            $z["befund"] = $befund[$key]["status"];

            $br = $k["Bruecke"];
            if ($br !== "" && ($zust[$br]["zustand"] ?? "") === self::Z_GESTOERT) {
                // Eingefroren: Die Bruecke ist gemeldet, das Geraet dahinter nicht.
                $z["hinterBruecke"] = true;
                $zust[$key] = $z;
                continue;
            }
            $z["hinterBruecke"] = false;
            if ($befund[$key]["status"] === self::B_AUSSETZEN) {
                // Quelle nicht abfragbar: nichts schliessen, nichts melden.
                $z["grundAussetzen"] = $befund[$key]["grund"];
                $zust[$key] = $z;
                continue;
            }
            unset($z["grundAussetzen"]);
            $inKarenz = $br !== "" && (int)($zust[$br]["okSeit"] ?? 0) > $jetzt - $karenz;

            $zust[$key] = $this->Uebergang($key, $k, $z, $befund[$key], $inKarenz, count($abhaengige[$key] ?? []), $jetzt);
        }

        // Nicht mehr ueberwachte Komponenten vergessen
        foreach (array_keys($zust) as $key) {
            if (!isset($befund[$key])) unset($zust[$key]);
        }

        $this->WriteAttributeString("Zustaende", json_encode($zust, JSON_UNESCAPED_UNICODE));
        $this->SetValue("LetztePruefung", $jetzt);
        $this->UebersichtRendern($zust, $liste);
    }

    private function Uebergang(string $key, array $k, array $z, array $b, bool $inKarenz, int $anzahlAbhaengige, int $jetzt): array {
        $status = $b["status"];

        switch ($z["zustand"]) {
            case self::Z_UNBEKANNT:
                if ($status === self::B_OK) {
                    $z["zustand"] = self::Z_OK; $z["seit"] = $jetzt; $z["okSeit"] = $jetzt; $z["grund"] = "";
                } elseif ($status === self::B_GESTOERT) {
                    // Schon beim Aufnehmen gestoert: Altbestand, kein Alarm.
                    $z["zustand"] = self::Z_ALTBESTAND; $z["seit"] = $jetzt; $z["grund"] = $b["grund"];
                    $z = $this->Ereignis($k, $key, "altbestand", $b["grund"], $z, 0);
                }
                break;

            case self::Z_ALTBESTAND:
                if ($status === self::B_OK) {
                    // Repariert - ab jetzt gilt die Komponente normal.
                    $z["zustand"] = self::Z_OK; $z["seit"] = $jetzt; $z["okSeit"] = $jetzt; $z["grund"] = "";
                } elseif ($status === self::B_GESTOERT) {
                    $z["grund"] = $b["grund"];
                }
                break;

            case self::Z_OK:
                if ($status === self::B_GESTOERT && !$inKarenz) {
                    $z["zaehler"] = (int)$z["zaehler"] + 1;
                    if ($z["zaehler"] >= max(1, $this->ReadPropertyInteger("Entprellung"))) {
                        $z["zustand"] = self::Z_GESTOERT; $z["seit"] = $jetzt; $z["grund"] = $b["grund"]; $z["zaehler"] = 0;
                        // Merken, woran der Ausfall erkannt wurde - davon haengt ab,
                        // wann die Bruecke wieder als da gilt.
                        $z["sammelausfall"] = !empty($b["sammelausfall"]);
                        $z = $this->Ereignis($k, $key, "gestoert", $b["grund"], $z, $anzahlAbhaengige);
                    }
                } else {
                    $z["zaehler"] = 0;
                }
                break;

            case self::Z_GESTOERT:
                if ($status === self::B_OK) {
                    $alterGrund = $z["grund"];
                    $z = $this->Ereignis($k, $key, "entwarnung", $alterGrund, $z, $anzahlAbhaengige);
                    $z["zustand"] = self::Z_OK; $z["seit"] = $jetzt; $z["okSeit"] = $jetzt; $z["grund"] = "";
                    $z["sammelausfall"] = false;
                } elseif ($status === self::B_GESTOERT) {
                    $z["grund"] = $b["grund"];
                }
                break;
        }

        // Wartung laeuft neben dem Zustand: Eine Komponente kann erreichbar
        // sein und trotzdem eine schwache Batterie haben. Ein Signal, das sich
        // nicht lesen laesst, ist ebenfalls ein Wartungsfall - meist ein
        // Konfigurationsfehler in der Liste.
        $wartung = $b["wartung"];
        if ($status === self::B_UNBEKANNT) $wartung = $b["grund"];

        // Entprellt wie ein Ausfall: Die Keymatic meldete beim Batteriewechsel
        // vier Minuten lang LOWBAT - das ergab eine Wartungs- und gleich darauf
        // eine Erledigt-Meldung. Erst wenn der Wechsel mehrere Pruefungen in
        // Folge ansteht, zaehlt er.
        $n = max(1, $this->ReadPropertyInteger("Entprellung"));
        if ($wartung !== null && $z["wartung"] === null) {
            $z["wartungZaehler"] = (int)($z["wartungZaehler"] ?? 0) + 1;
            if ($z["wartungZaehler"] >= $n) {
                $z["wartung"] = $wartung; $z["wartungSeit"] = $jetzt; $z["wartungZaehler"] = 0;
                $z = $this->Ereignis($k, $key, "wartung", $wartung, $z, 0);
            }
        } elseif ($wartung === null && $z["wartung"] !== null) {
            $z["wartungZaehler"] = (int)($z["wartungZaehler"] ?? 0) + 1;
            if ($z["wartungZaehler"] >= $n) {
                $z = $this->Ereignis($k, $key, "wartung_behoben", $z["wartung"], $z, 0);
                $z["wartung"] = null; $z["wartungSeit"] = 0; $z["wartungZaehler"] = 0;
            }
        } else {
            $z["wartungZaehler"] = 0;
            if ($wartung !== null) $z["wartung"] = $wartung;
        }
        return $z;
    }

    // ==================================================================
    // Meldungen
    // ==================================================================

    /**
     * Entscheidet, ob ein Ereignis sofort gemeldet oder in die Tagesuebersicht
     * aufgenommen wird, und merkt sich die MeldungID fuer den spaeteren Rueckzug.
     */
    private function Ereignis(array $k, string $key, string $art, string $grund, array $z, int $anzahlAbhaengige): array {
        $g = $k["Gewicht"];
        $istBruecke = $anzahlAbhaengige > 0;

        $sofort = false;
        if ($art === "gestoert" || $art === "entwarnung") {
            $sofort = $g === "sicherheit" || $g === "funktion" || $istBruecke;
        } elseif ($art === "wartung" || $art === "wartung_behoben") {
            $sofort = $g === "sicherheit";
        }

        $name = $k["Name"];
        if (!$sofort) {
            $liste = json_decode($this->ReadAttributeString("Tagesliste"), true);
            if (!is_array($liste)) $liste = [];
            $liste[] = ["zeit" => time(), "key" => $key, "name" => $name, "art" => $art, "grund" => $grund];
            $this->WriteAttributeString("Tagesliste", json_encode(array_slice($liste, -200), JSON_UNESCAPED_UNICODE));
            $this->Protokollieren($key, $art, "Tagesuebersicht", $grund);
            return $z;
        }

        $zeitpunkt = date("d.m. H:i");
        switch ($art) {
            case "gestoert":
                $titel = "Ausfall: " . $name;
                $text = $name . ": " . $grund . "."
                      . ($istBruecke ? " " . $anzahlAbhaengige . " Komponenten dahinter sind betroffen." : "");
                $dring = $g === "sicherheit" ? "wichtig" : "normal";
                $id = $this->Senden($titel, $text, $dring, "kwd-" . $key . "-gestoert-" . $z["seit"]);
                $z["meldungID"] = $id;
                break;
            case "entwarnung":
                $this->Zurueckziehen($z["meldungID"] ?? "");
                $z["meldungID"] = "";
                $seit = date("d.m. H:i", (int)$z["seit"]);
                $this->Senden("Wieder da: " . $name, $name . " meldet sich wieder (ausgefallen seit " . $seit . ").",
                              "normal", "kwd-" . $key . "-entwarnung-" . time());
                break;
            case "wartung":
                $id = $this->Senden("Wartung: " . $name, $name . ": " . $grund . ".", "normal",
                                    "kwd-" . $key . "-wartung-" . time());
                $z["wartungMeldungID"] = $id;
                break;
            case "wartung_behoben":
                $this->Zurueckziehen($z["wartungMeldungID"] ?? "");
                $z["wartungMeldungID"] = "";
                $this->Senden("Erledigt: " . $name, $name . ": " . $grund . " ist behoben.", "normal",
                              "kwd-" . $key . "-behoben-" . time());
                break;
        }
        $this->Protokollieren($key, $art, "sofort", $grund);
        return $z;
    }

    /** An die Meldungszentrale, vertraulich an den Empfaenger. Liefert die MeldungID oder "". */
    private function Senden(string $titel, string $text, string $dringlichkeit, string $dedupKey): string {
        $mz = $this->ReadPropertyInteger("MeldungszentraleID");
        if (!$this->ReadPropertyBoolean("MeldenAktiv")) {
            $this->SendDebug("Melden (aus)", $titel . " | " . $text, 0);
            return "";
        }
        if ($mz <= 0 || !IPS_InstanceExists($mz) || !function_exists("MZ_Melden")) {
            IPS_LogMessage("Komponentenwatchdog", "Keine Meldungszentrale - nicht gemeldet: " . $titel . " | " . $text);
            return "";
        }
        $antwort = @MZ_Melden($mz, json_encode([
            "quelle"        => $this->ReadPropertyString("Quelle"),
            "titel"         => $titel,
            "text"          => $text,
            "dringlichkeit" => $dringlichkeit,
            "empfaenger"    => [trim($this->ReadPropertyString("Empfaenger"))],
            // Nur so bleibt auch die gemeinsame Anzeige aussen vor.
            "vertraulich"   => true,
            "dedupKey"      => $dedupKey
        ], JSON_UNESCAPED_UNICODE));
        $d = json_decode((string)$antwort, true);
        if (is_array($d) && ($d["ok"] ?? false)) return (string)($d["meldungID"] ?? "");
        IPS_LogMessage("Komponentenwatchdog", "Meldung nicht angenommen: " . (string)$antwort . " | " . $titel);
        return "";
    }

    private function Zurueckziehen(string $meldungID) {
        if ($meldungID === "" || !$this->ReadPropertyBoolean("MeldenAktiv")) return;
        $mz = $this->ReadPropertyInteger("MeldungszentraleID");
        if ($mz > 0 && IPS_InstanceExists($mz) && function_exists("MZ_Zurueckziehen")) @MZ_Zurueckziehen($mz, $meldungID);
    }

    private function Protokollieren(string $key, string $art, string $weg, string $grund) {
        $p = json_decode($this->ReadAttributeString("Protokoll"), true);
        if (!is_array($p)) $p = [];
        $p[] = ["zeit" => date("d.m. H:i:s"), "key" => $key, "art" => $art, "weg" => $weg, "grund" => $grund,
                "gesendet" => $weg === "sofort" && $this->ReadPropertyBoolean("MeldenAktiv")];
        $this->WriteAttributeString("Protokoll", json_encode(array_slice($p, -50), JSON_UNESCAPED_UNICODE));
        $this->SendDebug("Ereignis", $key . " " . $art . " (" . $weg . "): " . $grund, 0);
    }

    // ==================================================================
    // Pruefregeln - eine je System
    // ==================================================================

    /** @return array ["status" => ok|gestoert|unbekannt, "grund" => string, "wartung" => ?string] */
    private function Befund(array $k): array {
        switch ($k["Regel"]) {
            case "instanzstatus": return $this->RegelInstanzstatus($k);
            case "homematic":     return $this->RegelHomematic($k);
            case "zigbee2mqtt":   return $this->RegelZigbee2MQTT($k);
            case "wertalter":     return $this->RegelWertalter($k);
        }
        return $this->Ergebnis(self::B_UNBEKANNT, "unbekannte Prüfregel " . $k["Regel"]);
    }

    private function RegelInstanzstatus(array $k): array {
        $id = $k["ObjektID"];
        if (!IPS_InstanceExists($id)) return $this->Ergebnis(self::B_UNBEKANNT, "Instanz " . $id . " existiert nicht");
        $s = IPS_GetInstance($id)["InstanceStatus"];
        // 104 = bewusst inaktiv, kein Fehler. Ab 200 meldet die Instanz einen Fehler.
        if ($s >= 200) return $this->Ergebnis(self::B_GESTOERT, "Instanz meldet Fehlerstatus " . $s);
        return $this->Ergebnis(self::B_OK);
    }

    /**
     * Homematic: Massgeblich sind die Servicemeldungen der CCU selbst
     * (HM_ReadServiceMessages am Socket) - nicht die Variablen in Symcon.
     *
     * Die Variablen sind nur eine Kopie, und die kann einfrieren: Beim
     * Rauchmelder Schlafzimmer stand LOWBAT seit Mai 2025 unveraendert auf
     * wahr, beim Funkausloeser UNREACH seit August 2023 - die CCU meldete fuer
     * beide laengst nichts mehr. Der erste Lauf hat deshalb zwei falsche
     * Wartungsmeldungen verschickt.
     */
    private function RegelHomematic(array $k): array {
        $id = $k["ObjektID"];
        if (!IPS_InstanceExists($id)) return $this->Ergebnis(self::B_UNBEKANNT, "Instanz " . $id . " existiert nicht");
        $adresse = (string)(json_decode(IPS_GetConfiguration($id), true)["Address"] ?? "");
        if ($adresse === "") return $this->Ergebnis(self::B_UNBEKANNT, "Objekt " . $id . " hat keine Homematic-Adresse");
        $geraet = explode(":", $adresse)[0];

        $meldungen = $this->CCUServicemeldungen((int)IPS_GetInstance($id)["ConnectionID"]);
        if ($meldungen === null) return $this->Ergebnis(self::B_AUSSETZEN, "CCU nicht abfragbar");

        $gestoert = null; $wartung = null;
        foreach ($meldungen as $m) {
            $a = (string)($m["Address"] ?? "");
            if ($a !== $adresse && strpos($a, $geraet . ":") !== 0) continue;
            if (empty($m["Value"])) continue;
            $art = strtoupper((string)($m["Message"] ?? ""));
            switch ($art) {
                case "UNREACH":
                    $gestoert = "nicht erreichbar (laut CCU)"; break;
                case "SABOTAGE":
                case "ERROR_SABOTAGE":
                    $gestoert = $gestoert ?? "Sabotage gemeldet (laut CCU)"; break;
                case "STICKY_UNREACH":
                case "STICKY_SABOTAGE":
                    // "war gestoert" - Vergangenheit, kein aktueller Befund.
                    break;
                case "LOWBAT":
                case "LOW_BAT":
                    $wartung = "Batterie schwach (laut CCU)"; break;
                case "CONFIG_PENDING":
                    // Steht nach jeder Konfigurationsaenderung kurz an - erst
                    // nach der eingestellten Dauer ein Wartungsfall.
                    $seit = $this->CCUErstGesehen($a . "|" . $art);
                    if (time() - $seit > max(1, $this->ReadPropertyInteger("ConfigPendingStunden")) * 3600) {
                        $wartung = $wartung ?? "Konfiguration ausstehend seit " . $this->Dauer(time() - $seit) . " (laut CCU)";
                    }
                    break;
                default:
                    $wartung = $wartung ?? "CCU meldet " . $art;
            }
        }
        if ($gestoert !== null) return $this->Ergebnis(self::B_GESTOERT, $gestoert, $wartung);
        return $this->Ergebnis(self::B_OK, "", $wartung);
    }

    /** Servicemeldungen einer CCU, einmal pro Pruefzyklus. null = nicht abfragbar. */
    private function CCUServicemeldungen(int $socket): ?array {
        if (array_key_exists($socket, $this->ccuCache)) return $this->ccuCache[$socket];
        $ergebnis = null;
        if ($socket > 0 && IPS_InstanceExists($socket) && function_exists("HM_ReadServiceMessages")) {
            try {
                $m = @HM_ReadServiceMessages($socket);
                if (is_array($m)) $ergebnis = $m;
            } catch (\Throwable $e) {
                $this->SendDebug("CCU", "Servicemeldungen nicht lesbar: " . $e->getMessage(), 0);
            }
        }
        $this->ccuCache[$socket] = $ergebnis;
        return $ergebnis;
    }

    private function CCUErstGesehen(string $schluessel): int {
        $e = json_decode($this->ReadAttributeString("CCUErstGesehen"), true);
        if (!is_array($e)) $e = [];
        if (!isset($e[$schluessel])) {
            $e[$schluessel] = time();
            $this->WriteAttributeString("CCUErstGesehen", json_encode($e));
        }
        $this->ccuCache["_gesehen"][$schluessel] = true;
        return (int)$e[$schluessel];
    }

    /** Vorbei ist vorbei: Nicht mehr gemeldete Eintraege vergessen. */
    private function CCUErstGesehenAufraeumen() {
        $e = json_decode($this->ReadAttributeString("CCUErstGesehen"), true);
        if (!is_array($e) || count($e) === 0) return;
        $gesehen = $this->ccuCache["_gesehen"] ?? [];
        $neu = array_intersect_key($e, $gesehen);
        if (count($neu) !== count($e)) $this->WriteAttributeString("CCUErstGesehen", json_encode($neu));
    }

    /**
     * Zigbee2MQTT: Zwei Anbindungen mit verschiedener Benennung - das neue
     * Modul nennt die Variablen deutsch ("Zuletzt gesehen", "Verfuegbarkeit"
     * als Bool), der alte Topic-Weg englisch ("last_seen" in Millisekunden).
     * Beides wird verstanden. "availability" hat Vorrang vor "last_seen", weil
     * Zigbee2MQTT dafuer die Eigenheiten des Geraets schon kennt.
     */
    private function RegelZigbee2MQTT(array $k): array {
        $v = $this->Kinder($k["ObjektID"]);
        $finde = function (array $namen) use ($v) {
            foreach ($namen as $n) if (isset($v[$n])) return $v[$n];
            return null;
        };

        $wartung = null;
        $bl = $finde(["battery_low", "batterie schwach"]);
        if ($bl !== null && GetValue($bl) === true) $wartung = "Batterie schwach";
        $bat = $finde(["battery", "batterie"]);
        if ($wartung === null && $bat !== null) {
            $w = GetValue($bat);
            if (is_numeric($w) && !is_bool($w) && $w <= $this->ReadPropertyInteger("BatterieProzent")) $wartung = "Batterie " . (int)$w . " %";
        }

        $av = $finde(["availability", "verfügbarkeit", "verfuegbarkeit"]);
        if ($av !== null) {
            $w = GetValue($av);
            $offline = (is_bool($w) && $w === false) || (is_string($w) && strtolower(trim($w)) === "offline");
            if ($offline) return $this->Ergebnis(self::B_GESTOERT, "Zigbee2MQTT meldet das Gerät als nicht verfügbar", $wartung);
            $online = (is_bool($w) && $w === true) || (is_string($w) && strtolower(trim($w)) === "online");
            if ($online) return $this->Ergebnis(self::B_OK, "", $wartung);
        }

        $ls = $finde(["last_seen", "zuletzt gesehen"]);
        if ($ls === null) {
            return $this->Ergebnis(self::B_UNBEKANNT, "kein Lebenszeichen (last_seen oder availability) unter Objekt " . $k["ObjektID"]);
        }
        $w = GetValue($ls);
        $ts = is_numeric($w) ? (float)$w : strtotime((string)$w);
        if ($ts > 100000000000) $ts = $ts / 1000;   // Millisekunden
        if ($ts <= 0) return $this->Ergebnis(self::B_UNBEKANNT, "last_seen nicht lesbar", $wartung);
        $alter = time() - (int)$ts;
        if ($alter > $this->Schwelle($k) * 60) return $this->Ergebnis(self::B_GESTOERT, "seit " . $this->Dauer($alter) . " still", $wartung);
        return $this->Ergebnis(self::B_OK, "", $wartung);
    }

    /** Fuer alles, was regelmaessig kommen muss: Pollwerte, Heartbeats. */
    private function RegelWertalter(array $k): array {
        $id = $k["ObjektID"];
        if (!IPS_VariableExists($id)) return $this->Ergebnis(self::B_UNBEKANNT, "Variable " . $id . " existiert nicht");
        $alter = time() - IPS_GetVariable($id)["VariableUpdated"];
        if ($alter > $this->Schwelle($k) * 60) return $this->Ergebnis(self::B_GESTOERT, "seit " . $this->Dauer($alter) . " keine Aktualisierung");
        return $this->Ergebnis(self::B_OK);
    }

    private function Ergebnis(string $status, string $grund = "", ?string $wartung = null): array {
        return ["status" => $status, "grund" => $grund, "wartung" => $wartung];
    }

    /** Variablen unter einem Objekt, nach Ident und Name in Kleinbuchstaben. */
    private function Kinder(int $id): array {
        $out = [];
        if (!IPS_ObjectExists($id)) return $out;
        foreach (IPS_GetChildrenIDs($id) as $c) {
            if (!IPS_VariableExists($c)) continue;
            $o = IPS_GetObject($c);
            $ident = mb_strtolower($o["ObjectIdent"]);
            $name = mb_strtolower($o["ObjectName"]);
            if ($ident !== "" && !isset($out[$ident])) $out[$ident] = $c;
            if (!isset($out[$name])) $out[$name] = $c;
        }
        return $out;
    }

    private function Schwelle(array $k): int {
        return $k["SchwelleMinuten"] > 0 ? $k["SchwelleMinuten"] : max(1, $this->ReadPropertyInteger("SchwelleStandardMinuten"));
    }

    private function Dauer(int $sekunden): string {
        if ($sekunden < 7200) return (int)round($sekunden / 60) . " min";
        if ($sekunden < 172800) return (int)round($sekunden / 3600) . " h";
        return (int)round($sekunden / 86400) . " Tagen";
    }

    // ==================================================================
    // Konfiguration
    // ==================================================================

    // ==================================================================
    // Flaechendeckende Abdeckung
    // ==================================================================

    /**
     * Ergaenzt die gepflegte Liste um alle Geraete der Systeme. Schluessel mit
     * Praefix (hm:, z2m:, inst:), Gewicht "allgemein". Ein Geraet, das die
     * Liste schon abdeckt - gleich mit welchem Gewicht oder Modus -, wird
     * nicht doppelt aufgenommen. So stellt ein Listeneintrag ein Geraet auch
     * ruhend.
     *
     * Die Ermittlung liest Instanzen und die Zigbee2MQTT-Geraeteliste und
     * wird deshalb nur alle paar Minuten erneuert, nicht in jedem Zyklus.
     */
    private function AutomatischeKomponenten(array $liste): array {
        $signatur = md5($this->ReadPropertyString("Komponenten") . "|" . IPS_GetConfiguration($this->InstanceID));
        $cache = json_decode($this->GetBuffer("Abdeckung"), true);
        $max = max(1, $this->ReadPropertyInteger("AbdeckungNeuErmittelnMinuten")) * 60;
        if (is_array($cache) && ($cache["signatur"] ?? "") === $signatur && time() - (int)$cache["zeit"] < $max) {
            return $cache["liste"];
        }

        $neu = [];
        $info = ["homematic" => 0, "zigbee" => 0, "zigbee_unangebunden" => [], "instanzen" => 0];

        if ($this->ReadPropertyBoolean("SystemHomematic")) $neu += $this->AbdeckungHomematic($liste, $info);
        if ($this->ReadPropertyBoolean("SystemZigbee")) {
            $z = $this->AbdeckungZigbee($liste, $info);
            if ($z === null && is_array($cache)) {
                // Zigbee2MQTT gerade nicht abfragbar: letzten Stand behalten,
                // sonst verschwaenden alle Zigbee-Geraete aus der Ueberwachung.
                foreach ($cache["liste"] as $k => $e) if (strpos($k, "z2m:") === 0) $neu[$k] = $e;
                $info["zigbee"] = $cache["info"]["zigbee"] ?? 0;
                $info["zigbee_unangebunden"] = $cache["info"]["zigbee_unangebunden"] ?? [];
            } elseif ($z !== null) {
                $neu += $z;
            }
        }
        if ($this->ReadPropertyBoolean("SystemInstanzen")) $neu += $this->AbdeckungInstanzen($liste, $info);

        $this->SetBuffer("Abdeckung", json_encode(["zeit" => time(), "signatur" => $signatur, "liste" => $neu, "info" => $info], JSON_UNESCAPED_UNICODE));
        return $neu;
    }

    private function AutoEintrag(string $key, string $name, string $regel, int $objekt, string $bruecke, array $liste): array {
        return ["Key" => $key, "Name" => $name, "Regel" => $regel, "ObjektID" => $objekt, "SchwelleMinuten" => 0,
                "Bruecke" => ($bruecke !== "" && isset($liste[$bruecke])) ? $bruecke : "",
                "Gewicht" => "allgemein", "Modus" => "aktiv", "Aktiv" => true, "Automatisch" => true];
    }

    /** Homematic: ein Eintrag je Geraet, ueber seinen Wartungskanal (Adresse ":0"). */
    private function AbdeckungHomematic(array $liste, array &$info): array {
        $belegt = [];
        foreach ($liste as $k) {
            if ($k["Regel"] !== "homematic" || !IPS_InstanceExists($k["ObjektID"])) continue;
            $a = (string)(json_decode(IPS_GetConfiguration($k["ObjektID"]), true)["Address"] ?? "");
            if ($a !== "") $belegt[explode(":", $a)[0]] = true;
        }
        $out = [];
        foreach (IPS_GetInstanceList() as $i) {
            if (IPS_GetInstance($i)["ModuleInfo"]["ModuleName"] !== "HomeMatic CCU Device") continue;
            $a = (string)(json_decode(IPS_GetConfiguration($i), true)["Address"] ?? "");
            if (substr($a, -2) !== ":0") continue;
            $geraet = explode(":", $a)[0];
            if (isset($belegt[$geraet])) continue;
            // Der Kanal heisst meist "MAINTENANCE" - der Geraetename steht am Ordner darueber.
            $name = IPS_GetName($i);
            $eltern = IPS_GetParent($i);
            if ($eltern > 0 && (stripos($name, "MAINTENANCE") !== false || strpos($name, $geraet) !== false)) $name = IPS_GetName($eltern);
            $out["hm:" . $geraet] = $this->AutoEintrag("hm:" . $geraet, $name, "homematic", $i, $this->ReadPropertyString("HomematicBruecke"), $liste);
            $info["homematic"]++;
        }
        return $out;
    }

    /**
     * Zigbee: Welche Geraete es gibt, weiss Zigbee2MQTT selbst. Das
     * Lebenszeichen kommt aus der Symcon-Instanz - bei mehreren (neues Modul
     * und alter Topic-Weg) aus der, die zuletzt etwas gehoert hat.
     * null = Zigbee2MQTT nicht abfragbar.
     */
    private function AbdeckungZigbee(array $liste, array &$info): ?array {
        $konf = $this->ReadPropertyInteger("Zigbee2MQTTKonfigurator");
        if ($konf <= 0 || !IPS_InstanceExists($konf) || !function_exists("Z2M_getDevices")) return null;
        try { $geraete = @Z2M_getDevices($konf); } catch (\Throwable $e) { $geraete = null; }
        if (!is_array($geraete) || count($geraete) === 0) return null;

        $perIEEE = []; $perName = [];
        foreach (IPS_GetInstanceList() as $i) {
            $m = IPS_GetInstance($i)["ModuleInfo"]["ModuleName"];
            if ($m === "Zigbee2MQTT Device") {
                $ieee = strtolower((string)(json_decode(IPS_GetConfiguration($i), true)["IEEE"] ?? ""));
                if ($ieee !== "") $perIEEE[$ieee][] = $i;
            } elseif ($m === "MQTT Client Device" && strpos(IPS_GetName($i), "zigbee2mqtt/") === 0) {
                $rest = substr(IPS_GetName($i), 12);
                if (strpos($rest, "/") === false) $perName[strtolower($rest)][] = $i;
            }
        }
        $belegt = [];
        foreach ($liste as $k) if ($k["Regel"] === "zigbee2mqtt") $belegt[$k["ObjektID"]] = true;

        $out = [];
        foreach ($geraete as $g) {
            if (($g["type"] ?? "") === "Coordinator") continue;
            $ieee = strtolower((string)($g["ieeeAddr"] ?? ""));
            $fn = (string)($g["friendly_name"] ?? $ieee);
            $kandidaten = array_merge($perIEEE[$ieee] ?? [], $perName[strtolower($fn)] ?? []);
            if (count($kandidaten) === 0) { $info["zigbee_unangebunden"][] = $fn; continue; }
            $schonDa = false;
            foreach ($kandidaten as $c) if (isset($belegt[$c])) $schonDa = true;
            if ($schonDa) continue;

            // Die Instanz mit dem juengsten Lebenszeichen nehmen
            $beste = $kandidaten[0]; $besteZeit = -1;
            foreach ($kandidaten as $c) {
                $t = $this->ZigbeeLetztesLebenszeichen($c);
                if ($t > $besteZeit) { $besteZeit = $t; $beste = $c; }
            }
            $out["z2m:" . $ieee] = $this->AutoEintrag("z2m:" . $ieee, $fn, "zigbee2mqtt", $beste, $this->ReadPropertyString("ZigbeeBruecke"), $liste);
            $info["zigbee"]++;
        }
        return $out;
    }

    private function ZigbeeLetztesLebenszeichen(int $instanz): int {
        $v = $this->Kinder($instanz);
        foreach (["last_seen", "zuletzt gesehen"] as $n) {
            if (!isset($v[$n])) continue;
            $w = GetValue($v[$n]);
            $ts = is_numeric($w) ? (float)$w : strtotime((string)$w);
            if ($ts > 100000000000) $ts /= 1000;
            return (int)$ts;
        }
        return 0;
    }

    /** Integrationen: Gateways, Sockets, MQTT-Clients und -Server, Splitter. */
    private function AbdeckungInstanzen(array $liste, array &$info): array {
        $belegt = [];
        foreach ($liste as $k) if ($k["Regel"] === "instanzstatus") $belegt[$k["ObjektID"]] = true;
        $out = [];
        foreach (IPS_GetInstanceList() as $i) {
            $inst = IPS_GetInstance($i);
            if (!in_array($inst["ModuleInfo"]["ModuleType"], [1, 2])) continue;   // E/A und Splitter
            if (isset($belegt[$i])) continue;
            $out["inst:" . $i] = $this->AutoEintrag("inst:" . $i, IPS_GetName($i), "instanzstatus", $i, "", $liste);
            $info["instanzen"]++;
        }
        return $out;
    }

    private function KomponentenListe(): array {
        $roh = json_decode($this->ReadPropertyString("Komponenten"), true);
        $out = [];
        if (!is_array($roh)) return $out;
        foreach ($roh as $r) {
            $key = trim((string)($r["Key"] ?? ""));
            if ($key === "" || isset($out[$key])) continue;
            $out[$key] = [
                "Key"             => $key,
                "Name"            => trim((string)($r["Name"] ?? "")) !== "" ? trim((string)$r["Name"]) : $key,
                "Regel"           => (string)($r["Regel"] ?? ""),
                "ObjektID"        => (int)($r["ObjektID"] ?? 0),
                "SchwelleMinuten" => (int)($r["SchwelleMinuten"] ?? 0),
                "Bruecke"         => trim((string)($r["Bruecke"] ?? "")),
                "Gewicht"         => in_array($r["Gewicht"] ?? "", self::GEWICHTE) ? $r["Gewicht"] : "allgemein",
                "Modus"           => ($r["Modus"] ?? "aktiv") === "ruhend" ? "ruhend" : "aktiv",
                "Aktiv"           => (bool)($r["Aktiv"] ?? true)
            ];
        }
        return $out;
    }

    private function Ueberwacht(array $k): bool {
        return $k["Aktiv"] && $k["Modus"] === "aktiv" && in_array($k["Regel"], self::REGELN) && $k["ObjektID"] > 0;
    }

    private function KonfigurationPruefen(): string {
        $roh = json_decode($this->ReadPropertyString("Komponenten"), true);
        if (!is_array($roh)) return "Komponentenliste ist kein gueltiges JSON";
        $keys = [];
        foreach ($roh as $r) {
            $key = trim((string)($r["Key"] ?? ""));
            if ($key === "") return "Komponente ohne Schluessel";
            if (isset($keys[$key])) return "Schluessel doppelt: " . $key;
            $keys[$key] = true;
            if (!in_array($r["Regel"] ?? "", self::REGELN)) return "Unbekannte Pruefregel bei " . $key;
        }
        foreach ($roh as $r) {
            $b = trim((string)($r["Bruecke"] ?? ""));
            if ($b !== "" && !isset($keys[$b])) return "Bruecke " . $b . " von " . $r["Key"] . " ist nicht in der Liste";
            if ($b === trim((string)$r["Key"])) return "Komponente " . $b . " ist ihre eigene Bruecke";
        }
        return "";
    }

    private function TimerStellen() {
        $this->SetTimerInterval("Pruefung", max(1, $this->ReadPropertyInteger("PruefintervallMinuten")) * 60 * 1000);
        $this->TimerTagesuebersicht();
    }

    private function TimerTagesuebersicht() {
        $teile = explode(":", $this->ReadPropertyString("Tagesuebersicht"));
        $h = max(0, min(23, (int)($teile[0] ?? 18)));
        $m = max(0, min(59, (int)($teile[1] ?? 0)));
        $ziel = mktime($h, $m, 0);
        if ($ziel <= time() + 30) $ziel = strtotime("+1 day", $ziel);
        $this->SetTimerInterval("Tagesuebersicht", ($ziel - time()) * 1000);
    }

    // ==================================================================
    // Uebersicht
    // ==================================================================

    private function UebersichtRendern(array $zust, array $liste) {
        $gestoert = []; $wartung = []; $altbestand = []; $unbekannt = []; $bruecke = [];
        foreach ($zust as $key => $z) {
            if (!empty($z["hinterBruecke"])) $bruecke[] = $z;
            elseif ($z["zustand"] === self::Z_GESTOERT) $gestoert[] = $z;
            elseif ($z["zustand"] === self::Z_ALTBESTAND) $altbestand[] = $z;
            elseif ($z["zustand"] === self::Z_UNBEKANNT) $unbekannt[] = $z;
            if ($z["wartung"] !== null) $wartung[] = $z;
        }
        $this->SetValue("AnzahlGestoert", count($gestoert));
        $this->SetValue("AnzahlWartung", count($wartung));

        // Farben inline und ohne Text-/Hintergrundfarbe - wie die Anzeige der
        // Meldungszentrale, damit es im hellen wie im dunklen WebFront lesbar bleibt.
        $block = function (string $titel, string $farbe, array $eintraege, bool $mitWartung) {
            if (count($eintraege) === 0) return "";
            $h = '<div style="margin:10px 0 4px 0;font-weight:600">' . htmlspecialchars($titel) . ' (' . count($eintraege) . ')</div>';
            foreach ($eintraege as $z) {
                $grund = $mitWartung ? $z["wartung"] : $z["grund"];
                $seit = $mitWartung ? (int)$z["wartungSeit"] : (int)$z["seit"];
                $h .= '<div style="margin:0 0 4px 0;padding:5px 9px;border-left:4px solid ' . $farbe . ';background:rgba(128,128,128,.12)">'
                    . '<span style="font-weight:500">' . htmlspecialchars($z["name"]) . '</span>'
                    . ' <span style="opacity:.75">' . htmlspecialchars((string)$grund) . '</span>'
                    . ' <span style="opacity:.5;font-size:88%">seit ' . date("d.m. H:i", $seit) . '</span></div>';
            }
            return $h;
        };

        $ueberwacht = count($zust);
        $abd = json_decode($this->GetBuffer("Abdeckung"), true);
        $ai = is_array($abd) ? ($abd["info"] ?? []) : [];
        $h = '<div style="font:inherit;line-height:1.35">'
           . '<div style="opacity:.6;font-size:90%">' . $ueberwacht . ' Komponenten überwacht · geprüft ' . date("H:i") . '</div>'
           . '<div style="opacity:.5;font-size:85%">davon automatisch: Homematic ' . (int)($ai["homematic"] ?? 0)
           . ' · Zigbee ' . (int)($ai["zigbee"] ?? 0) . ' · Integrationen ' . (int)($ai["instanzen"] ?? 0) . '</div>';
        if (!empty($ai["zigbee_unangebunden"])) {
            $h .= '<div style="opacity:.5;font-size:85%">Zigbee ohne Symcon-Instanz, nicht bewertbar: '
                . htmlspecialchars(implode(", ", $ai["zigbee_unangebunden"])) . '</div>';
        }
        if (count($gestoert) + count($wartung) + count($altbestand) + count($unbekannt) + count($bruecke) === 0) {
            $h .= '<div style="padding:10px 0;opacity:.7">Alles in Ordnung.</div>';
        }
        $h .= $block("Ausgefallen", "#e53935", $gestoert, false);
        $h .= $block("Wartung", "#fb8c00", $wartung, true);
        $h .= $block("Hinter ausgefallener Brücke", "#9e9e9e", $bruecke, false);
        $h .= $block("Noch nicht bewertbar", "#9e9e9e", $unbekannt, false);
        $h .= $block("Bei Aufnahme schon gestört (Altbestand)", "#9e9e9e", $altbestand, false);
        $h .= '</div>';
        $this->SetValue("Uebersicht", $h);
    }
}
