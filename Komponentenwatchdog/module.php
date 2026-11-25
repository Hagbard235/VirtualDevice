<?php

/**
 * Komponentenwatchdog
 *
 * Fragt fuer eine gepflegte Liste von Komponenten, ob sie noch leben - jede mit
 * der Methode, die ihr System erlaubt - und sagt Bescheid, wenn sich die
 * Antwort aendert.
 *
 * Erweiterung v2 (Zigbee-Router & Batterie-Ableitung):
 * - Trennt Zigbee-Geraete in router_infra, router_licht, batterie_sensor und batterie_taster.
 * - Leitet Batteriezustaende primaer aus last_seen-Stille (plus lernender Check-in-Baseline)
 *   sowie ausschließlich frischen battery_low-, Spannungs- und Prozentwerten ab.
 * - Erkennt eingefrorene availability/device_status-Werte, Symcon-Topic-Mismatches
 *   und haeufig ausfallende (flappende) Router innerhalb von 24 Stunden.
 * - Unterstuetzt Modus "saisonal" neben "aktiv" und "ruhend" sowie KWD_ZigbeeBericht().
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

    /** Laufzeit-Cache fuer ZigbeeTakt waehrend eines Pruefzyklus. */
    private $taktCache = null;

    /** Zigbee-Metadaten je Schluessel und "obj:<id>". */
    private $zigbeeMeta = [];

    const REGELN         = ["instanzstatus", "homematic", "zigbee2mqtt", "wertalter"];
    const GEWICHTE       = ["sicherheit", "funktion", "allgemein"];
    const MODI           = ["aktiv", "ruhend", "saisonal"];
    const ZIGBEE_KLASSEN = ["router_infra", "router_licht", "batterie_sensor", "batterie_taster"];

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

        // Zigbee v2 Schwellen
        $this->RegisterPropertyInteger("RouterSchwelleMinuten", 120);
        $this->RegisterPropertyInteger("LichtRouterSchwelleMinuten", 360);
        $this->RegisterPropertyInteger("BatterieSensorWarnStunden", 24);
        $this->RegisterPropertyInteger("BatterieSensorAusfallStunden", 48);
        $this->RegisterPropertyInteger("BatterieTasterWarnStunden", 72);
        $this->RegisterPropertyInteger("BatterieTasterAusfallStunden", 168);
        $this->RegisterPropertyFloat("BatterieSpannungMin", 2.4);
        $this->RegisterPropertyInteger("FlappingSchwelle24h", 3);

        // Flaechendeckend je System.
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
        $this->RegisterAttributeString("CCUErstGesehen", "{}");
        $this->RegisterAttributeString("ZigbeeTakt", "{}");

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

        // Was am selben Tag ausfiel und zurueckkam, hebt sich auf - ausser
        // dauerhaft anstehende Wartungsfaelle (z. B. Flapping oder Batterie).
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

    /** Strukturierter Zigbee-Bericht (Router, Batterien, Takte, Flaps, Unangebundene). */
    public function ZigbeeBericht(): string {
        $jetzt = time();
        $liste = $this->KomponentenListe();
        $auto = $this->AutomatischeKomponenten($liste);
        $alle = $liste + $auto;
        $zust = json_decode($this->ReadAttributeString("Zustaende"), true);
        if (!is_array($zust)) $zust = [];
        $takt = json_decode($this->ReadAttributeString("ZigbeeTakt"), true);
        if (!is_array($takt)) $takt = [];
        $this->taktCache = $takt;

        $abd = json_decode($this->GetBuffer("Abdeckung"), true);
        $ai = is_array($abd) ? ($abd["info"] ?? []) : [];

        $geraete = [];
        $ruhendSaisonal = [];
        $zaehler = [
            "gesamt_ueberwacht" => 0,
            "router_infra" => 0,
            "router_licht" => 0,
            "batterie_sensor" => 0,
            "batterie_taster" => 0,
            "batterie_pruefen_oder_leer" => 0,
            "instabil_flapping" => 0,
            "topic_mismatch" => 0,
            "unangebunden_router" => 0,
            "unangebunden_endgeraete" => 0
        ];

        foreach ($alle as $key => $k) {
            if (($k["Regel"] ?? "") !== "zigbee2mqtt") continue;
            $meta = $this->MetaFuerKomponente($k);
            $klasse = $meta["klasse"] ?? $this->ZigbeeKlasseFallback((int)$k["ObjektID"]);
            $te = $this->TaktLaden($key);
            $flaps = $this->FlapsLetzte24h($key, $jetzt);
            $v = IPS_ObjectExists((int)$k["ObjektID"]) ? $this->Kinder((int)$k["ObjektID"]) : [];
            $ts = $this->ZigbeeLastSeenAusVariablen($v, $jetzt);
            $stilleH = $ts > 0 ? round(max(0, $jetzt - $ts) / 3600, 2) : null;
            $schwellen = $this->ZigbeeSchwellen($k, $klasse);
            $p85 = $this->ZigbeePerzentil85($te["pausen"] ?? []);
            $z = $zust[$key] ?? [];

            $batInfo = $this->ZigbeeBatterieWerte($v, $ts, $jetzt);
            $av = $this->ZigbeeVerfuegbarkeit($v);

            if ($k["Aktiv"] && $k["Modus"] !== "aktiv") {
                $ruhendSaisonal[] = [
                    "key" => $key,
                    "name" => $k["Name"],
                    "modus" => $k["Modus"],
                    "klasse" => $klasse,
                    "objektID" => (int)$k["ObjektID"],
                    "last_seen" => $ts > 0 ? $ts : null,
                    "stille_stunden" => $stilleH,
                    "aktuell_aktiv" => ($ts > 0 && ($jetzt - $ts) <= 86400)
                ];
                continue;
            }
            if (!$this->Ueberwacht($k)) continue;

            $zaehler["gesamt_ueberwacht"]++;
            if (isset($zaehler[$klasse])) $zaehler[$klasse]++;
            if (!empty($meta["topicMismatch"])) $zaehler["topic_mismatch"]++;
            if ($flaps >= max(2, $this->ReadPropertyInteger("FlappingSchwelle24h"))) $zaehler["instabil_flapping"]++;
            $wart = (string)($z["wartung"] ?? "");
            $grnd = (string)($z["grund"] ?? "");
            if (stripos($wart, "Batterie") !== false || stripos($wart, "Spannung") !== false || stripos($grnd, "Batterie") !== false) {
                $zaehler["batterie_pruefen_oder_leer"]++;
            }

            $geraete[] = [
                "key" => $key,
                "name" => $k["Name"],
                "objektID" => (int)$k["ObjektID"],
                "klasse" => $klasse,
                "model" => $meta["model"] ?? "",
                "ieee" => $meta["ieee"] ?? "",
                "symconTopic" => $meta["symconTopic"] ?? "",
                "topicMismatch" => $meta["topicMismatch"] ?? "",
                "zustand" => $z["zustand"] ?? self::Z_UNBEKANNT,
                "grund" => $z["grund"] ?? "",
                "wartung" => $z["wartung"] ?? null,
                "last_seen" => $ts > 0 ? $ts : null,
                "stille_stunden" => $stilleH,
                "takt_p85_stunden" => $p85 !== null ? round($p85 / 3600, 2) : null,
                "takt_proben" => count($te["pausen"] ?? []),
                "warnschwelle_stunden" => $schwellen["warn"] > 0 ? round($schwellen["warn"] / 3600, 2) : null,
                "ausfallschwelle_stunden" => round($schwellen["ausfall"] / 3600, 2),
                "flaps_24h" => $flaps,
                "batterie_prozent" => $batInfo["batterie"],
                "batterie_frisch" => $batInfo["batterie_frisch"],
                "spannung_v" => $batInfo["spannung_v"],
                "spannung_frisch" => $batInfo["spannung_frisch"],
                "battery_low" => $batInfo["battery_low"],
                "verfuegbarkeit" => $av["text"]
            ];
        }

        $unangRouter = [];
        $unangEnd = [];
        foreach ((array)($ai["zigbee_unangebunden"] ?? []) as $u) {
            if (is_string($u)) {
                $unangEnd[] = ["name" => $u, "klasse" => "unbekannt"];
                continue;
            }
            if (!is_array($u)) continue;
            $kl = (string)($u["klasse"] ?? "batterie_sensor");
            if ($kl === "router_infra" || $kl === "router_licht") {
                $unangRouter[] = $u;
            } else {
                $unangEnd[] = $u;
            }
        }
        $zaehler["unangebunden_router"] = count($unangRouter);
        $zaehler["unangebunden_endgeraete"] = count($unangEnd);

        $this->taktCache = null;

        return json_encode([
            "erstellt" => $jetzt,
            "zusammenfassung" => $zaehler,
            "geraete" => $geraete,
            "unangebunden_router" => $unangRouter,
            "unangebunden_endgeraete" => $unangEnd,
            "ruhend_saisonal" => $ruhendSaisonal
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Eine Komponente neu beginnen lassen, als waere sie gerade aufgenommen.
     * Leerer Schluessel setzt alle zurueck.
     */
    public function Zuruecksetzen(string $Key) {
        $z = json_decode($this->ReadAttributeString("Zustaende"), true);
        if (!is_array($z)) $z = [];
        if ($Key === "") $z = []; else unset($z[$Key]);
        $this->WriteAttributeString("Zustaende", json_encode($z, JSON_UNESCAPED_UNICODE));

        $t = json_decode($this->ReadAttributeString("Tagesliste"), true);
        if (!is_array($t)) $t = [];
        $t = $Key === "" ? [] : array_values(array_filter($t, function ($e) use ($Key) { return ($e["key"] ?? "") !== $Key; }));
        $this->WriteAttributeString("Tagesliste", json_encode($t, JSON_UNESCAPED_UNICODE));

        $takt = json_decode($this->ReadAttributeString("ZigbeeTakt"), true);
        if (!is_array($takt)) $takt = [];
        if ($Key === "") $takt = []; else unset($takt[$Key]);
        $this->WriteAttributeString("ZigbeeTakt", json_encode($takt, JSON_UNESCAPED_UNICODE));
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

        $takt = json_decode($this->ReadAttributeString("ZigbeeTakt"), true);
        $this->taktCache = is_array($takt) ? $takt : [];

        // Wer ist Bruecke fuer wen?
        $abhaengige = [];
        foreach ($liste as $key => $k) {
            if ($k["Bruecke"] !== "" && isset($liste[$k["Bruecke"]])) $abhaengige[$k["Bruecke"]][] = $key;
        }

        // 1. Befunde
        $this->ccuCache = [];
        $befund = [];
        foreach ($liste as $key => $k) {
            if ($this->Ueberwacht($k)) $befund[$key] = $this->Befund($k, $jetzt);
        }
        $this->CCUErstGesehenAufraeumen();

        // 2. Sammelausfall hinter einer Bruecke
        $prozent = max(1, $this->ReadPropertyInteger("SammelausfallProzent"));
        foreach ($abhaengige as $b => $keys) {
            if (!isset($befund[$b])) continue;
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

            if ($zbZustand === self::Z_GESTOERT && !empty($zb["sammelausfall"])) {
                if ($lebend === 0 && $bekannt > 0) {
                    $befund[$b]["status"] = self::B_GESTOERT;
                    $befund[$b]["grund"] = $zb["grund"];
                }
                $befund[$b]["sammelausfall"] = true;
                continue;
            }

            if ($zbZustand !== self::Z_OK || $befund[$b]["status"] !== self::B_OK) continue;
            if ($gesamt >= 3 && $still * 100 >= $gesamt * $prozent) {
                $befund[$b]["status"] = self::B_GESTOERT;
                $befund[$b]["grund"] = "Sammelausfall: " . $still . " von " . $gesamt . " Komponenten dahinter still";
                $befund[$b]["sammelausfall"] = true;
            }
        }

        // 3. Uebergaenge - Bruecken vor den Geraeten dahinter
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
            if ($k["Regel"] === "zigbee2mqtt") {
                $meta = $this->MetaFuerKomponente($k);
                $z["klasse"] = $meta["klasse"] ?? $this->ZigbeeKlasseFallback((int)$k["ObjektID"]);
            }

            $br = $k["Bruecke"];
            if ($br !== "" && ($zust[$br]["zustand"] ?? "") === self::Z_GESTOERT) {
                $z["hinterBruecke"] = true;
                $zust[$key] = $z;
                continue;
            }
            $z["hinterBruecke"] = false;
            if ($befund[$key]["status"] === self::B_AUSSETZEN) {
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
        foreach (array_keys($this->taktCache) as $key) {
            if (!isset($befund[$key]) && !isset($liste[$key])) unset($this->taktCache[$key]);
        }

        $this->WriteAttributeString("Zustaende", json_encode($zust, JSON_UNESCAPED_UNICODE));
        $this->WriteAttributeString("ZigbeeTakt", json_encode($this->taktCache, JSON_UNESCAPED_UNICODE));
        $this->taktCache = null;

        $this->SetValue("LetztePruefung", $jetzt);
        $this->UebersichtRendern($zust, $liste, $jetzt);
    }

    private function Uebergang(string $key, array $k, array $z, array $b, bool $inKarenz, int $anzahlAbhaengige, int $jetzt): array {
        $status = $b["status"];

        switch ($z["zustand"]) {
            case self::Z_UNBEKANNT:
                if ($status === self::B_OK) {
                    $z["zustand"] = self::Z_OK; $z["seit"] = $jetzt; $z["okSeit"] = $jetzt; $z["grund"] = "";
                } elseif ($status === self::B_GESTOERT) {
                    $z["zustand"] = self::Z_ALTBESTAND; $z["seit"] = $jetzt; $z["grund"] = $b["grund"];
                    $z = $this->Ereignis($k, $key, "altbestand", $b["grund"], $z, 0);
                }
                break;

            case self::Z_ALTBESTAND:
                if ($status === self::B_OK) {
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
                        $z["sammelausfall"] = !empty($b["sammelausfall"]);
                        $this->FlapAufzeichnen($key, $jetzt);
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

        // Wartung laeuft neben dem Zustand
        $wartung = $b["wartung"];
        if ($status === self::B_UNBEKANNT) $wartung = $b["grund"];

        // Flapping-Erkennung (24h-Ausfallzaehler) ausser bei Altbestand
        if ($z["zustand"] !== self::Z_ALTBESTAND) {
            $flaps = $this->FlapsLetzte24h($key, $jetzt);
            $flapSchwelle = max(2, $this->ReadPropertyInteger("FlappingSchwelle24h"));
            if ($flaps >= $flapSchwelle) {
                $flapHinweis = "Instabil: " . $flaps . " Ausfälle in 24 h";
                $wartung = ($wartung !== null && $wartung !== "") ? ($wartung . "; " . $flapHinweis) : $flapHinweis;
            }
        }

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
    // Pruefregeln
    // ==================================================================

    private function Befund(array $k, int $jetzt): array {
        switch ($k["Regel"]) {
            case "instanzstatus": return $this->RegelInstanzstatus($k);
            case "homematic":     return $this->RegelHomematic($k, $jetzt);
            case "zigbee2mqtt":   return $this->RegelZigbee2MQTT($k, $jetzt);
            case "wertalter":     return $this->RegelWertalter($k, $jetzt);
        }
        return $this->Ergebnis(self::B_UNBEKANNT, "unbekannte Prüfregel " . $k["Regel"]);
    }

    private function RegelInstanzstatus(array $k): array {
        $id = $k["ObjektID"];
        if (!IPS_InstanceExists($id)) return $this->Ergebnis(self::B_UNBEKANNT, "Instanz " . $id . " existiert nicht");
        $s = IPS_GetInstance($id)["InstanceStatus"];
        if ($s >= 200) return $this->Ergebnis(self::B_GESTOERT, "Instanz meldet Fehlerstatus " . $s);
        return $this->Ergebnis(self::B_OK);
    }

    private function RegelHomematic(array $k, int $jetzt): array {
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
                    break;
                case "LOWBAT":
                case "LOW_BAT":
                    $wartung = "Batterie schwach (laut CCU)"; break;
                case "CONFIG_PENDING":
                    $seit = $this->CCUErstGesehen($a . "|" . $art, $jetzt);
                    if ($jetzt - $seit > max(1, $this->ReadPropertyInteger("ConfigPendingStunden")) * 3600) {
                        $wartung = $wartung ?? "Konfiguration ausstehend seit " . $this->Dauer($jetzt - $seit) . " (laut CCU)";
                    }
                    break;
                default:
                    $wartung = $wartung ?? "CCU meldet " . $art;
            }
        }
        if ($gestoert !== null) return $this->Ergebnis(self::B_GESTOERT, $gestoert, $wartung);
        return $this->Ergebnis(self::B_OK, "", $wartung);
    }

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

    private function CCUErstGesehen(string $schluessel, int $jetzt): int {
        $e = json_decode($this->ReadAttributeString("CCUErstGesehen"), true);
        if (!is_array($e)) $e = [];
        if (!isset($e[$schluessel])) {
            $e[$schluessel] = $jetzt;
            $this->WriteAttributeString("CCUErstGesehen", json_encode($e));
        }
        $this->ccuCache["_gesehen"][$schluessel] = true;
        return (int)$e[$schluessel];
    }

    private function CCUErstGesehenAufraeumen() {
        $e = json_decode($this->ReadAttributeString("CCUErstGesehen"), true);
        if (!is_array($e) || count($e) === 0) return;
        $gesehen = $this->ccuCache["_gesehen"] ?? [];
        $neu = array_intersect_key($e, $gesehen);
        if (count($neu) !== count($e)) $this->WriteAttributeString("CCUErstGesehen", json_encode($neu));
    }

    /**
     * Zigbee2MQTT v2:
     * - Bewertet Router und Batterie-Endgeraete nach getrennten Klassen-Schwellen.
     * - availability/device_status "online" ueberdeckt NIEMALS ein abgelaufenes last_seen.
     * - Prueft Topic-Mismatches zwischen Symcon-Instanz und Zigbee2MQTT friendly_name.
     * - Wertet battery_low, Spannung und Batterie-% nur bei frischem last_seen (<= 7 Tage)
     *   und frischem VariableUpdated (> 0 und <= 14 Tage).
     */
    private function RegelZigbee2MQTT(array $k, int $jetzt): array {
        $objId = (int)$k["ObjektID"];
        if (!IPS_ObjectExists($objId)) {
            return $this->Ergebnis(self::B_UNBEKANNT, "Objekt " . $objId . " existiert nicht");
        }

        $meta = $this->MetaFuerKomponente($k);
        $klasse = $meta["klasse"] ?? $this->ZigbeeKlasseFallback($objId);
        $istRouter = ($klasse === "router_infra" || $klasse === "router_licht");
        $schwellen = $this->ZigbeeSchwellen($k, $klasse);
        $warnSek = $schwellen["warn"];
        $ausfallSek = $schwellen["ausfall"];

        $v = $this->Kinder($objId);
        $ts = $this->ZigbeeLastSeenAusVariablen($v, $jetzt);
        if ($ts > 0) {
            $this->ZigbeeTaktAufzeichnen($k["Key"], $ts);
        }
        $alter = ($ts > 0) ? max(0, $jetzt - $ts) : null;

        $av = $this->ZigbeeVerfuegbarkeit($v);
        $avOffline = $av["offline"];

        // Topic-Mismatch mit fehlendem oder veraltetem last_seen -> Konfigurationsfehler
        $mismatch = trim((string)($meta["topicMismatch"] ?? ""));
        $mismatchGrenze = $warnSek > 0 ? $warnSek : $ausfallSek;
        if ($mismatch !== "" && ($alter === null || $alter > $mismatchGrenze)) {
            return $this->Ergebnis(self::B_UNBEKANNT, $mismatch . " – empfängt keine Updates");
        }

        // Frische Batteriesignale nur fuer Nicht-Router
        $batWartung = null;
        if (!$istRouter) {
            $bat = $this->ZigbeeBatterieWerte($v, $ts, $jetzt);
            $hinweise = [];
            if ($bat["battery_low"] === true) {
                $hinweise[] = "battery_low aktiv";
            }
            $minVolt = (float)$this->ReadPropertyFloat("BatterieSpannungMin");
            if ($bat["spannung_frisch"] && $bat["spannung_v"] !== null && $minVolt > 0 && $bat["spannung_v"] <= $minVolt) {
                $hinweise[] = "Spannung " . number_format($bat["spannung_v"], 2, ",", "") . " V";
            }
            $minPct = $this->ReadPropertyInteger("BatterieProzent");
            if ($bat["batterie_frisch"] && $bat["batterie"] !== null && $bat["batterie"] <= $minPct) {
                $hinweise[] = "Batterie laut Gerät " . (int)$bat["batterie"] . " %";
            }
            if (count($hinweise) > 0) {
                $batWartung = implode("; ", $hinweise);
            }
        }

        if ($alter === null) {
            if ($avOffline) {
                return $this->Ergebnis(self::B_GESTOERT, "Zigbee2MQTT meldet das Gerät als nicht verfügbar", $batWartung);
            }
            return $this->Ergebnis(self::B_UNBEKANNT, "kein Lebenszeichen (last_seen) unter Objekt " . $objId);
        }

        if ($istRouter) {
            $rolleLabel = ($klasse === "router_licht") ? "Licht-Router" : "Router";
            if ($alter > $ausfallSek) {
                $grund = $rolleLabel . " seit " . $this->Dauer($alter) . " still" . ($avOffline ? " (Z2M offline)" : "");
                return $this->Ergebnis(self::B_GESTOERT, $grund);
            }
            if ($avOffline) {
                return $this->Ergebnis(
                    self::B_GESTOERT,
                    $rolleLabel . " von Zigbee2MQTT als nicht verfügbar gemeldet (zuletzt vor " . $this->Dauer($alter) . ")"
                );
            }
            return $this->Ergebnis(self::B_OK);
        }

        // Batterie-Endgeraet
        if ($alter >= $ausfallSek) {
            $grund = "Batterie vermutlich leer – seit " . $this->Dauer($alter) . " still" . ($avOffline ? " (Z2M offline)" : "");
            return $this->Ergebnis(self::B_GESTOERT, $grund, $batWartung);
        }
        if ($avOffline) {
            if ($alter >= $warnSek) {
                return $this->Ergebnis(
                    self::B_GESTOERT,
                    "Batterie vermutlich leer – seit " . $this->Dauer($alter) . " still (Z2M offline)",
                    $batWartung
                );
            }
            return $this->Ergebnis(
                self::B_GESTOERT,
                "Zigbee2MQTT meldet nicht verfügbar (zuletzt vor " . $this->Dauer($alter) . ")",
                $batWartung
            );
        }
        if ($alter >= $warnSek) {
            $wText = "Batterie prüfen – seit " . $this->Dauer($alter) . " still (normal ≤ " . $this->Dauer($warnSek) . ")";
            if ($batWartung !== null) $wText .= "; " . $batWartung;
            return $this->Ergebnis(self::B_OK, "", $wText);
        }

        return $this->Ergebnis(self::B_OK, "", $batWartung);
    }

    private function RegelWertalter(array $k, int $jetzt): array {
        $id = $k["ObjektID"];
        if (!IPS_VariableExists($id)) return $this->Ergebnis(self::B_UNBEKANNT, "Variable " . $id . " existiert nicht");
        $alter = $jetzt - (int)IPS_GetVariable($id)["VariableUpdated"];
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
        if ($sekunden < 0) $sekunden = 0;
        if ($sekunden < 7200) return (int)round($sekunden / 60) . " min";
        if ($sekunden < 172800) return (int)round($sekunden / 3600) . " h";
        return (int)round($sekunden / 86400) . " Tagen";
    }

    // ==================================================================
    // Zigbee-Helfer: Klassifizierung, Takt, Signale, Flapping
    // ==================================================================

    private function ZigbeeLastSeenAusVariablen(array $v, int $jetzt): int {
        foreach (["last_seen", "zuletzt gesehen"] as $n) {
            if (!isset($v[$n])) continue;
            $w = GetValue($v[$n]);
            if (is_bool($w) || $w === "" || $w === null) continue;
            $ts = is_numeric($w) ? (float)$w : strtotime((string)$w);
            if ($ts === false || $ts <= 0) continue;
            if ($ts > 100000000000) $ts /= 1000.0;
            $ti = (int)round($ts);
            if ($ti <= 0 || $ti > $jetzt + 300) continue;
            return $ti;
        }
        return 0;
    }

    private function ZigbeeVerfuegbarkeit(array $v): array {
        foreach (["availability", "device_status", "verfügbarkeit", "verfuegbarkeit"] as $n) {
            if (!isset($v[$n])) continue;
            $w = GetValue($v[$n]);
            if (is_bool($w)) {
                return ["offline" => ($w === false), "online" => ($w === true), "text" => $w ? "online" : "offline"];
            }
            if (is_string($w)) {
                $wl = strtolower(trim($w));
                if ($wl === "offline" || $wl === "false" || $wl === "0") {
                    return ["offline" => true, "online" => false, "text" => "offline"];
                }
                if ($wl === "online" || $wl === "true" || $wl === "1") {
                    return ["offline" => false, "online" => true, "text" => "online"];
                }
            }
        }
        return ["offline" => false, "online" => false, "text" => ""];
    }

    private function ZigbeeBatterieWerte(array $v, int $lastSeenTs, int $jetzt): array {
        $lastSeenFrisch = ($lastSeenTs > 0 && ($jetzt - $lastSeenTs) <= 7 * 86400);

        $varFrisch = function (?int $varId) use ($lastSeenFrisch, $jetzt): bool {
            if ($varId === null || !$lastSeenFrisch) return false;
            $vi = @IPS_GetVariable($varId);
            $upd = (int)($vi["VariableUpdated"] ?? 0);
            if ($upd <= 0 || $upd > $jetzt + 300) return false;
            return ($jetzt - $upd) <= 14 * 86400;
        };

        $finde = function (array $namen) use ($v): ?int {
            foreach ($namen as $n) if (isset($v[$n])) return $v[$n];
            return null;
        };

        $batteryLow = null;
        $blVar = $finde(["battery_low", "batterie schwach"]);
        if ($blVar !== null && $varFrisch($blVar)) {
            $bw = GetValue($blVar);
            $batteryLow = ($bw === true || $bw === 1 || $bw === "true");
        }

        $spannungV = null;
        $spannungFrisch = false;
        $voltVar = $finde(["voltage", "spannung"]);
        if ($voltVar !== null && $varFrisch($voltVar)) {
            $vw = GetValue($voltVar);
            if (is_numeric($vw) && !is_bool($vw)) {
                $vf = (float)$vw;
                if ($vf > 0) {
                    if ($vf > 50 && $vf < 10000) $vf = $vf / 1000.0;
                    if ($vf > 0.5 && $vf <= 10.0) {
                        $spannungV = round($vf, 3);
                        $spannungFrisch = true;
                    }
                }
            }
        }

        $batteriePct = null;
        $batterieFrisch = false;
        $batVar = $finde(["battery", "batterie"]);
        if ($batVar !== null && $varFrisch($batVar)) {
            $w = GetValue($batVar);
            if (is_numeric($w) && !is_bool($w)) {
                $batteriePct = (int)round((float)$w);
                $batterieFrisch = true;
            }
        }

        return [
            "battery_low"     => $batteryLow,
            "spannung_v"      => $spannungV,
            "spannung_frisch" => $spannungFrisch,
            "batterie"        => $batteriePct,
            "batterie_frisch" => $batterieFrisch
        ];
    }

    private function ZigbeeKlasse(array $g): string {
        $type = strtolower((string)($g["type"] ?? ""));
        $power = strtolower((string)($g["powerSource"] ?? ""));
        $text = strtolower(implode(" ", [
            (string)($g["description"] ?? ""),
            (string)($g["model"] ?? ""),
            (string)($g["modelID"] ?? ""),
            (string)($g["friendly_name"] ?? "")
        ]));

        $istRouter = ($type === "router") || (strpos($power, "mains") !== false) || (strpos($power, "dc") !== false);
        if ($istRouter) {
            foreach (["light", "lamp", "bulb", "spot", "hue", "gu10", "e27", "e14", "e26",
                      "ledvance", "innr", "rgb", "multicolor", "dimmable", "deckenlampe",
                      "deckenspot", "wandlampe", "leuchte"] as $kw) {
                if (strpos($text, $kw) !== false) return "router_licht";
            }
            return "router_infra";
        }

        foreach (["switch", "button", "remote", "keypad", "cube", "styrbar", "symfonisk",
                  "shortcut", "taster", "knopf", "drehknopf", "dimmer", "pupualarm"] as $kw) {
            if (strpos($text, $kw) !== false) return "batterie_taster";
        }
        return "batterie_sensor";
    }

    private function ZigbeeKlasseFallback(int $objId): string {
        if ($objId <= 0 || !IPS_ObjectExists($objId)) return "batterie_sensor";
        $v = $this->Kinder($objId);
        if (isset($v["device__powersource"])) {
            $ps = strtolower((string)GetValue($v["device__powersource"]));
            if (strpos($ps, "mains") !== false || strpos($ps, "dc") !== false) return "router_infra";
        }
        if (isset($v["voltage"]) || isset($v["spannung"])) {
            $vid = $v["voltage"] ?? $v["spannung"];
            $val = GetValue($vid);
            if (is_numeric($val) && (float)$val > 100 && (float)$val < 400) return "router_infra";
        }
        $name = strtolower(IPS_GetName($objId));
        if (strpos($name, "hue") !== false || strpos($name, "ledv") !== false || strpos($name, "spot") !== false) {
            return "router_licht";
        }
        if (strpos($name, "taster") !== false || strpos($name, "cube") !== false || strpos($name, "styr") !== false
            || strpos($name, "symf") !== false || strpos($name, "knopf") !== false || strpos($name, "onoff") !== false) {
            return "batterie_taster";
        }
        return "batterie_sensor";
    }

    private function ZigbeeSchwellen(array $k, string $klasse): array {
        if ($klasse === "router_infra") {
            $ausfall = ($k["SchwelleMinuten"] > 0)
                ? $k["SchwelleMinuten"] * 60
                : max(1, $this->ReadPropertyInteger("RouterSchwelleMinuten")) * 60;
            return ["warn" => 0, "ausfall" => $ausfall];
        }
        if ($klasse === "router_licht") {
            $ausfall = ($k["SchwelleMinuten"] > 0)
                ? $k["SchwelleMinuten"] * 60
                : max(1, $this->ReadPropertyInteger("LichtRouterSchwelleMinuten")) * 60;
            return ["warn" => 0, "ausfall" => $ausfall];
        }

        if ($klasse === "batterie_taster") {
            $warnCfg = max(1, $this->ReadPropertyInteger("BatterieTasterWarnStunden")) * 3600;
            $ausfallCfg = max($warnCfg, max(1, $this->ReadPropertyInteger("BatterieTasterAusfallStunden")) * 3600);
            $floorSek = min($warnCfg, 48 * 3600);
        } else {
            $warnCfg = max(1, $this->ReadPropertyInteger("BatterieSensorWarnStunden")) * 3600;
            $ausfallCfg = max($warnCfg, max(1, $this->ReadPropertyInteger("BatterieSensorAusfallStunden")) * 3600);
            $floorSek = min($warnCfg, 12 * 3600);
        }

        $warnSek = $this->ZigbeeDynamischeWarnSchwelle($k["Key"], $warnCfg, $floorSek);
        if ($k["SchwelleMinuten"] > 0) {
            $ausfallSek = $k["SchwelleMinuten"] * 60;
            $warnSek = min($warnSek, $ausfallSek);
        } else {
            $ausfallSek = $ausfallCfg;
        }
        return ["warn" => $warnSek, "ausfall" => $ausfallSek];
    }

    private function TaktLaden(string $key): array {
        if ($this->taktCache === null) {
            $raw = json_decode($this->ReadAttributeString("ZigbeeTakt"), true);
            $this->taktCache = is_array($raw) ? $raw : [];
        }
        $e = $this->taktCache[$key] ?? [];
        if (!is_array($e)) $e = [];
        return [
            "last_ts"      => (int)($e["last_ts"] ?? 0),
            "pausen"       => is_array($e["pausen"] ?? null) ? array_values($e["pausen"]) : [],
            "ausfaelle24h" => is_array($e["ausfaelle24h"] ?? null) ? array_values($e["ausfaelle24h"]) : []
        ];
    }

    private function ZigbeeTaktAufzeichnen(string $key, int $ts) {
        if ($ts <= 0) return;
        $e = $this->TaktLaden($key);
        if ($e["last_ts"] > 0 && $ts > $e["last_ts"]) {
            $delta = $ts - $e["last_ts"];
            if ($delta >= 1800 && $delta <= 14 * 86400) {
                $e["pausen"][] = $delta;
                if (count($e["pausen"]) > 12) {
                    $e["pausen"] = array_slice($e["pausen"], -12);
                }
            }
        }
        if ($ts > $e["last_ts"]) {
            $e["last_ts"] = $ts;
        }
        $this->taktCache[$key] = $e;
    }

    private function ZigbeePerzentil85(array $pausen): ?int {
        $n = count($pausen);
        if ($n < 5) return null;
        $sortiert = array_map("intval", $pausen);
        sort($sortiert, SORT_NUMERIC);
        $pos = 0.85 * ($n - 1);
        $lo = (int)floor($pos);
        $hi = (int)ceil($pos);
        if ($lo === $hi) return $sortiert[$lo];
        $frac = $pos - $lo;
        return (int)round($sortiert[$lo] + ($sortiert[$hi] - $sortiert[$lo]) * $frac);
    }

    private function ZigbeeDynamischeWarnSchwelle(string $key, int $warnCfgSek, int $floorSek): int {
        $e = $this->TaktLaden($key);
        $p85 = $this->ZigbeePerzentil85($e["pausen"]);
        if ($p85 === null) return $warnCfgSek;
        return min($warnCfgSek, max($floorSek, (int)round($p85 * 3)));
    }

    private function FlapAufzeichnen(string $key, int $jetzt) {
        $e = $this->TaktLaden($key);
        $grenze = $jetzt - 86400;
        $neu = [];
        foreach ($e["ausfaelle24h"] as $t) {
            $ti = (int)$t;
            if ($ti >= $grenze && $ti <= $jetzt + 60) $neu[] = $ti;
        }
        $neu[] = $jetzt;
        $e["ausfaelle24h"] = array_slice($neu, -20);
        $this->taktCache[$key] = $e;
    }

    private function FlapsLetzte24h(string $key, int $jetzt): int {
        $e = $this->TaktLaden($key);
        $grenze = $jetzt - 86400;
        $neu = [];
        foreach ($e["ausfaelle24h"] as $t) {
            $ti = (int)$t;
            if ($ti >= $grenze && $ti <= $jetzt + 60) $neu[] = $ti;
        }
        if (count($neu) !== count($e["ausfaelle24h"])) {
            $e["ausfaelle24h"] = $neu;
            $this->taktCache[$key] = $e;
        }
        return count($neu);
    }

    private function MetaFuerKomponente(array $k): array {
        $key = (string)($k["Key"] ?? "");
        if ($key !== "" && isset($this->zigbeeMeta[$key])) return $this->zigbeeMeta[$key];
        $objKey = "obj:" . (int)($k["ObjektID"] ?? 0);
        if (isset($this->zigbeeMeta[$objKey])) return $this->zigbeeMeta[$objKey];
        return [];
    }

    // ==================================================================
    // Flaechendeckende Abdeckung
    // ==================================================================

    private function AutomatischeKomponenten(array $liste): array {
        $signatur = md5($this->ReadPropertyString("Komponenten") . "|" . IPS_GetConfiguration($this->InstanceID) . "|v2");
        $cache = json_decode($this->GetBuffer("Abdeckung"), true);
        $max = max(1, $this->ReadPropertyInteger("AbdeckungNeuErmittelnMinuten")) * 60;
        if (is_array($cache) && ($cache["signatur"] ?? "") === $signatur && time() - (int)$cache["zeit"] < $max) {
            $this->zigbeeMeta = is_array($cache["meta"] ?? null) ? $cache["meta"] : [];
            return $cache["liste"];
        }

        $neu = [];
        $meta = [];
        $info = ["homematic" => 0, "zigbee" => 0, "zigbee_unangebunden" => [], "instanzen" => 0];

        if ($this->ReadPropertyBoolean("SystemHomematic")) $neu += $this->AbdeckungHomematic($liste, $info);
        if ($this->ReadPropertyBoolean("SystemZigbee")) {
            $z = $this->AbdeckungZigbee($liste, $info, $meta);
            if ($z === null && is_array($cache)) {
                foreach ($cache["liste"] as $k => $e) if (strpos($k, "z2m:") === 0) $neu[$k] = $e;
                $info["zigbee"] = $cache["info"]["zigbee"] ?? 0;
                $info["zigbee_unangebunden"] = $cache["info"]["zigbee_unangebunden"] ?? [];
                $meta = is_array($cache["meta"] ?? null) ? $cache["meta"] : [];
            } elseif ($z !== null) {
                $neu += $z;
            }
        }
        if ($this->ReadPropertyBoolean("SystemInstanzen")) $neu += $this->AbdeckungInstanzen($liste, $info);

        $this->zigbeeMeta = $meta;
        $this->SetBuffer("Abdeckung", json_encode([
            "zeit" => time(),
            "signatur" => $signatur,
            "liste" => $neu,
            "info" => $info,
            "meta" => $meta
        ], JSON_UNESCAPED_UNICODE));
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
            $name = IPS_GetName($i);
            $eltern = IPS_GetParent($i);
            if ($eltern > 0 && (stripos($name, "MAINTENANCE") !== false || strpos($name, $geraet) !== false)) $name = IPS_GetName($eltern);
            $out["hm:" . $geraet] = $this->AutoEintrag("hm:" . $geraet, $name, "homematic", $i, $this->ReadPropertyString("HomematicBruecke"), $liste);
            $info["homematic"]++;
        }
        return $out;
    }

    /**
     * Zigbee: Liest alle Geraete aus Zigbee2MQTT, klassifiziert nach Rolle,
     * waehlt die zum Topic passende Symcon-Instanz mit juengstem last_seen
     * und erkennt Topic-Mismatches sowie unangebundene Router/Endgeraete.
     */
    private function AbdeckungZigbee(array $liste, array &$info, array &$meta): ?array {
        $konf = $this->ReadPropertyInteger("Zigbee2MQTTKonfigurator");
        if ($konf <= 0 || !IPS_InstanceExists($konf) || !function_exists("Z2M_getDevices")) return null;
        try { $geraete = @Z2M_getDevices($konf); } catch (\Throwable $e) { $geraete = null; }
        if (!is_array($geraete) || count($geraete) === 0) return null;

        $perIEEE = []; $perName = []; $perTopic = []; $instInfo = [];
        foreach (IPS_GetInstanceList() as $i) {
            $m = IPS_GetInstance($i)["ModuleInfo"]["ModuleName"];
            if ($m === "Zigbee2MQTT Device") {
                $cfg = json_decode(IPS_GetConfiguration($i), true) ?: [];
                $ieee = strtolower(trim((string)($cfg["IEEE"] ?? "")));
                $topic = trim((string)($cfg["MQTTTopic"] ?? ""));
                $instInfo[$i] = ["module" => $m, "ieee" => $ieee, "topic" => $topic];
                if ($ieee !== "") $perIEEE[$ieee][] = $i;
                if ($topic !== "") $perTopic[strtolower($topic)][] = $i;
            } elseif ($m === "MQTT Client Device" && strpos(IPS_GetName($i), "zigbee2mqtt/") === 0) {
                $rest = substr(IPS_GetName($i), 12);
                if (strpos($rest, "/") === false) {
                    $cfg = json_decode(IPS_GetConfiguration($i), true) ?: [];
                    $t = trim((string)($cfg["Topic"] ?? ""));
                    if (strpos($t, "zigbee2mqtt/") === 0) $t = substr($t, 12);
                    if ($t === "") $t = $rest;
                    $instInfo[$i] = ["module" => $m, "ieee" => "", "topic" => $t];
                    $perName[strtolower($rest)][] = $i;
                    if ($t !== "" && strtolower($t) !== strtolower($rest)) {
                        $perTopic[strtolower($t)][] = $i;
                    }
                }
            }
        }

        $belegt = [];
        foreach ($liste as $key => $k) {
            if ($k["Regel"] === "zigbee2mqtt") $belegt[(int)$k["ObjektID"]] = $key;
        }

        $out = [];
        foreach ($geraete as $g) {
            if (strtolower((string)($g["type"] ?? "")) === "coordinator") continue;
            $ieee = strtolower(trim((string)($g["ieeeAddr"] ?? "")));
            $fn = (string)($g["friendly_name"] ?? $ieee);
            $fnLow = strtolower($fn);
            $klasse = $this->ZigbeeKlasse($g);

            $kandidaten = array_values(array_unique(array_merge(
                $perIEEE[$ieee] ?? [],
                $perTopic[$fnLow] ?? [],
                $perName[$fnLow] ?? []
            )));

            if (count($kandidaten) === 0) {
                $info["zigbee_unangebunden"][] = [
                    "name"        => $fn,
                    "ieee"        => $ieee,
                    "klasse"      => $klasse,
                    "type"        => (string)($g["type"] ?? ""),
                    "powerSource" => (string)($g["powerSource"] ?? ""),
                    "model"       => (string)($g["model"] ?? "")
                ];
                continue;
            }

            // Topic-passende Instanzen bevorzugen, sonst alle Kandidaten pruefen
            $passende = [];
            foreach ($kandidaten as $c) {
                $t = strtolower((string)($instInfo[$c]["topic"] ?? ""));
                if ($t !== "" && $t === $fnLow) $passende[] = $c;
            }
            $pool = count($passende) > 0 ? $passende : $kandidaten;

            $beste = $pool[0]; $besteZeit = -1;
            foreach ($pool as $c) {
                $t = $this->ZigbeeLetztesLebenszeichen($c);
                if ($t > $besteZeit) { $besteZeit = $t; $beste = $c; }
            }

            $instTopic = (string)($instInfo[$beste]["topic"] ?? "");
            $topicMismatch = "";
            if (count($passende) === 0 && strtolower($instTopic) !== $fnLow) {
                if ($instTopic !== "") {
                    $topicMismatch = "Symcon-Topic '" . $instTopic . "' != Z2M-Name '" . $fn . "'";
                } elseif (($instInfo[$beste]["module"] ?? "") === "Zigbee2MQTT Device") {
                    $topicMismatch = "Symcon-Topic leer != Z2M-Name '" . $fn . "'";
                }
            }

            $m = [
                "key"           => "z2m:" . $ieee,
                "name"          => $fn,
                "ieee"          => $ieee,
                "klasse"        => $klasse,
                "type"          => (string)($g["type"] ?? ""),
                "powerSource"   => (string)($g["powerSource"] ?? ""),
                "model"         => (string)($g["model"] ?? ""),
                "description"   => (string)($g["description"] ?? ""),
                "objektID"      => $beste,
                "symconTopic"   => $instTopic,
                "topicMismatch" => $topicMismatch
            ];

            $manuellKey = null;
            foreach ($kandidaten as $c) {
                if (isset($belegt[$c])) { $manuellKey = $belegt[$c]; break; }
            }
            if ($manuellKey !== null) {
                $manObj = (int)$liste[$manuellKey]["ObjektID"];
                $m["key"] = $manuellKey;
                $m["objektID"] = $manObj;
                $meta[$manuellKey] = $m;
                $meta["obj:" . $manObj] = $m;
                continue;
            }

            $autoKey = "z2m:" . $ieee;
            $meta[$autoKey] = $m;
            $meta["obj:" . $beste] = $m;
            $out[$autoKey] = $this->AutoEintrag($autoKey, $fn, "zigbee2mqtt", $beste, $this->ReadPropertyString("ZigbeeBruecke"), $liste);
            $info["zigbee"]++;
        }
        return $out;
    }

    private function ZigbeeLetztesLebenszeichen(int $instanz): int {
        $v = $this->Kinder($instanz);
        return $this->ZigbeeLastSeenAusVariablen($v, time());
    }

    /** Integrationen: Gateways, Sockets, MQTT-Clients und -Server, Splitter. */
    private function AbdeckungInstanzen(array $liste, array &$info): array {
        $belegt = [];
        foreach ($liste as $k) if ($k["Regel"] === "instanzstatus") $belegt[$k["ObjektID"]] = true;
        $out = [];
        foreach (IPS_GetInstanceList() as $i) {
            $inst = IPS_GetInstance($i);
            if (!in_array($inst["ModuleInfo"]["ModuleType"], [1, 2])) continue;
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
            $modus = strtolower(trim((string)($r["Modus"] ?? "aktiv")));
            if (!in_array($modus, self::MODI, true)) $modus = "aktiv";
            $out[$key] = [
                "Key"             => $key,
                "Name"            => trim((string)($r["Name"] ?? "")) !== "" ? trim((string)$r["Name"]) : $key,
                "Regel"           => (string)($r["Regel"] ?? ""),
                "ObjektID"        => (int)($r["ObjektID"] ?? 0),
                "SchwelleMinuten" => (int)($r["SchwelleMinuten"] ?? 0),
                "Bruecke"         => trim((string)($r["Bruecke"] ?? "")),
                "Gewicht"         => in_array($r["Gewicht"] ?? "", self::GEWICHTE, true) ? $r["Gewicht"] : "allgemein",
                "Modus"           => $modus,
                "Aktiv"           => (bool)($r["Aktiv"] ?? true)
            ];
        }
        return $out;
    }

    private function Ueberwacht(array $k): bool {
        return $k["Aktiv"] && $k["Modus"] === "aktiv" && in_array($k["Regel"], self::REGELN, true) && $k["ObjektID"] > 0;
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
            if (!in_array($r["Regel"] ?? "", self::REGELN, true)) return "Unbekannte Pruefregel bei " . $key;
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

    private function UebersichtRendern(array $zust, array $liste, int $jetzt) {
        $gestoertRouter = [];
        $gestoertBatterie = [];
        $gestoertSonst = [];
        $wartung = [];
        $altbestand = [];
        $unbekannt = [];
        $bruecke = [];

        foreach ($zust as $key => $z) {
            if (!empty($z["hinterBruecke"])) {
                $bruecke[] = $z;
            } elseif ($z["zustand"] === self::Z_GESTOERT) {
                $kl = (string)($z["klasse"] ?? "");
                if (($z["regel"] ?? "") === "zigbee2mqtt" && ($kl === "router_infra" || $kl === "router_licht")) {
                    $gestoertRouter[] = $z;
                } elseif (($z["regel"] ?? "") === "zigbee2mqtt") {
                    $gestoertBatterie[] = $z;
                } else {
                    $gestoertSonst[] = $z;
                }
            } elseif ($z["zustand"] === self::Z_ALTBESTAND) {
                $altbestand[] = $z;
            } elseif ($z["zustand"] === self::Z_UNBEKANNT) {
                $unbekannt[] = $z;
            }
            if ($z["wartung"] !== null) $wartung[] = $z;
        }

        $gesamtGestoert = count($gestoertRouter) + count($gestoertBatterie) + count($gestoertSonst);
        $this->SetValue("AnzahlGestoert", $gesamtGestoert);
        $this->SetValue("AnzahlWartung", count($wartung));

        $klasseTag = function (array $z): string {
            $kl = (string)($z["klasse"] ?? "");
            switch ($kl) {
                case "router_infra":    return "[Router] ";
                case "router_licht":    return "[Licht-Router] ";
                case "batterie_taster": return "[Taster] ";
                case "batterie_sensor": return "[Sensor] ";
                default:                return "";
            }
        };

        $block = function (string $titel, string $farbe, array $eintraege, bool $mitWartung, bool $mitRolle = false) use ($klasseTag): string {
            if (count($eintraege) === 0) return "";
            $h = '<div style="margin:10px 0 4px 0;font-weight:600">' . htmlspecialchars($titel) . ' (' . count($eintraege) . ')</div>';
            foreach ($eintraege as $z) {
                $grund = $mitWartung ? $z["wartung"] : $z["grund"];
                $seit = $mitWartung ? (int)$z["wartungSeit"] : (int)$z["seit"];
                $prefix = $mitRolle ? '<span style="opacity:.65;font-size:88%">' . htmlspecialchars($klasseTag($z)) . '</span>' : "";
                $h .= '<div style="margin:0 0 4px 0;padding:5px 9px;border-left:4px solid ' . $farbe . ';background:rgba(128,128,128,.12)">'
                    . $prefix
                    . '<span style="font-weight:500">' . htmlspecialchars((string)$z["name"]) . '</span>'
                    . ' <span style="opacity:.75">' . htmlspecialchars((string)$grund) . '</span>'
                    . ' <span style="opacity:.5;font-size:88%">seit ' . date("d.m. H:i", $seit) . '</span></div>';
            }
            return $h;
        };

        $ueberwacht = count($zust);
        $abd = json_decode($this->GetBuffer("Abdeckung"), true);
        $ai = is_array($abd) ? ($abd["info"] ?? []) : [];
        $h = '<div style="font:inherit;line-height:1.35">'
           . '<div style="opacity:.6;font-size:90%">' . $ueberwacht . ' Komponenten überwacht · geprüft ' . date("H:i", $jetzt) . '</div>'
           . '<div style="opacity:.5;font-size:85%">davon automatisch: Homematic ' . (int)($ai["homematic"] ?? 0)
           . ' · Zigbee ' . (int)($ai["zigbee"] ?? 0) . ' · Integrationen ' . (int)($ai["instanzen"] ?? 0) . '</div>';

        // Ruhend / Saisonal sammeln
        $ruhendEintraege = [];
        foreach ($liste as $k) {
            if (!$k["Aktiv"] || $k["Modus"] === "aktiv") continue;
            $hinweis = "Modus: " . $k["Modus"];
            if ($k["Modus"] === "saisonal" && $k["Regel"] === "zigbee2mqtt" && IPS_ObjectExists((int)$k["ObjektID"])) {
                $ts = $this->ZigbeeLastSeenAusVariablen($this->Kinder((int)$k["ObjektID"]), $jetzt);
                if ($ts > 0 && ($jetzt - $ts) <= 86400) {
                    $hinweis .= " – aktuell aktiv! (zuletzt vor " . $this->Dauer($jetzt - $ts) . ")";
                }
            }
            $ruhendEintraege[] = ["name" => $k["Name"], "hinweis" => $hinweis];
        }

        // Unangebundene Zigbee-Geraete trennen in Router vs. Endgeraete
        $unangRouter = [];
        $unangEnd = [];
        foreach ((array)($ai["zigbee_unangebunden"] ?? []) as $u) {
            if (is_string($u)) { $unangEnd[] = $u; continue; }
            if (!is_array($u)) continue;
            $label = (string)($u["name"] ?? "");
            if (!empty($u["model"])) $label .= " (" . $u["model"] . ")";
            $kl = (string)($u["klasse"] ?? "");
            if ($kl === "router_infra" || $kl === "router_licht") {
                $unangRouter[] = $label;
            } else {
                $unangEnd[] = $label;
            }
        }

        if ($gesamtGestoert + count($wartung) + count($altbestand) + count($unbekannt) + count($bruecke) + count($unangRouter) === 0) {
            $h .= '<div style="padding:10px 0;opacity:.7">Alles in Ordnung.</div>';
        }

        $h .= $block("Ausgefallen – Router & Infrastruktur", "#d32f2f", $gestoertRouter, false, true);
        $h .= $block("Batterie vermutlich leer / Sensor still", "#e53935", $gestoertBatterie, false, true);
        $h .= $block("Batterie prüfen / Wartung & instabile Router", "#fb8c00", $wartung, true, true);
        $h .= $block("Ausgefallen – Sonstige", "#e53935", $gestoertSonst, false);
        $h .= $block("Hinter ausgefallener Brücke", "#9e9e9e", $bruecke, false);
        $h .= $block("Noch nicht bewertbar / Topic-Mismatch", "#9e9e9e", $unbekannt, false);

        if (count($unangRouter) + count($unangEnd) > 0) {
            $h .= '<div style="margin:10px 0 4px 0;font-weight:600">Zigbee ohne Symcon-Instanz (' . (count($unangRouter) + count($unangEnd)) . ')</div>';
            if (count($unangRouter) > 0) {
                $h .= '<div style="margin:0 0 4px 0;padding:5px 9px;border-left:4px solid #fb8c00;background:rgba(128,128,128,.12)">'
                    . '<span style="font-weight:500">Router (Mesh-relevant):</span> '
                    . '<span style="opacity:.85">' . htmlspecialchars(implode(", ", $unangRouter)) . '</span></div>';
            }
            if (count($unangEnd) > 0) {
                $h .= '<div style="margin:0 0 4px 0;padding:5px 9px;border-left:4px solid #9e9e9e;background:rgba(128,128,128,.12)">'
                    . '<span style="font-weight:500">Endgeräte:</span> '
                    . '<span style="opacity:.75">' . htmlspecialchars(implode(", ", $unangEnd)) . '</span></div>';
            }
        }

        if (count($ruhendEintraege) > 0) {
            $h .= '<div style="margin:10px 0 4px 0;font-weight:600">Ruhend / Saisonal (' . count($ruhendEintraege) . ')</div>';
            foreach ($ruhendEintraege as $re) {
                $h .= '<div style="margin:0 0 4px 0;padding:5px 9px;border-left:4px solid #78909c;background:rgba(128,128,128,.12)">'
                    . '<span style="font-weight:500">' . htmlspecialchars($re["name"]) . '</span>'
                    . ' <span style="opacity:.75">' . htmlspecialchars($re["hinweis"]) . '</span></div>';
            }
        }

        $h .= $block("Bei Aufnahme schon gestört (Altbestand)", "#9e9e9e", $altbestand, false, true);
        $h .= '</div>';
        $this->SetValue("Uebersicht", $h);
    }
}
