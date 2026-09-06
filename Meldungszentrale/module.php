<?php

/**
 * Meldungszentrale
 *
 * Nimmt Meldungen aus dem Haus entgegen und entscheidet, wer sie wann ueber
 * welchen Kanal erhaelt. Zustellpolitik wird einmal zentral geloest statt in
 * jeder Quelle und jedem Ausgabeweg erneut.
 *
 * Konzept: 11_Konzept_Meldungszentrale.md (Fassung 4, korrigiert).
 *
 * Zwei Grundsaetze, die den Rest erklaeren:
 *
 * 1. Die Zustellgarantie haengt an der Meldungsdatei, nicht an einem Kanal.
 *    Erst wenn sie geschrieben ist, wird ueberhaupt ein Kanal versucht.
 * 2. Harte Schutzfilter (Adressierung, Darstellbarkeit, Vertraulichkeit,
 *    Aktionserlaubnis) gelten IMMER - auch bei PolitikAktiv = false und im
 *    Alarm-Fallback. Nur die weichen Politikfilter sind abschaltbar.
 */
class Meldungszentrale extends IPSModule {

    const DRINGLICHKEIT = ["info" => 0, "normal" => 1, "wichtig" => 2, "alarm" => 3];
    const STOERGRAD     = ["keiner" => 0, "niedrig" => 1, "mittel" => 2, "hoch" => 3];

    // Welcher Stoergrad in der Ruhezeit je Dringlichkeit noch durchkommt.
    // -1 = gar keiner (nur stille Kanaele wie die Anzeige).
    const RUHE_DURCHGRIFF = ["info" => -1, "normal" => 1, "wichtig" => 2, "alarm" => 3];

    // Vorgabegueltigkeit, wenn die Meldung keine Aktion traegt. Es gibt
    // bewusst KEINE unbegrenzt gueltige Meldung - Anzeige, Aufraeumlauf und
    // Deduplizierung brauchen alle ein definiertes Ende.
    const VORGABE_GUELTIG = ["info" => 3600, "normal" => 21600, "wichtig" => 86400, "alarm" => 86400];

    const AUFTRAG_GEPLANT    = "geplant";
    const AUFTRAG_ANGENOMMEN = "adapter_angenommen";
    const AUFTRAG_BESTAETIGT = "transport_bestaetigt";
    const AUFTRAG_FEHLER     = "fehlgeschlagen";
    const AUFTRAG_AUFGEGEBEN = "aufgegeben";

    const AKTION_OFFEN      = "offen";
    const AKTION_LAEUFT     = "wird_ausgefuehrt";
    const AKTION_ERFOLG     = "erfolgreich";
    const AKTION_FEHLER     = "fehlgeschlagen";
    const AKTION_UNBEKANNT  = "ausgang_unbekannt";
    const AKTION_ABGELAUFEN = "abgelaufen";

    const LOCK_MS = 2000;
    const RETRY_ABSTAENDE = [10, 30, 120];

    // ==================================================================
    // Lebenszyklus
    // ==================================================================

    public function Create() {
        parent::Create();

        $this->RegisterPropertyString("Zonen", "[]");
        $this->RegisterPropertyString("Empfaenger", "[]");
        $this->RegisterPropertyString("Kanaele", "[]");
        $this->RegisterPropertyString("Quellen", "[]");
        $this->RegisterPropertyString("Aktionen", "[]");

        // Schaltet die WEICHEN Filter ab (Rueckbau auf Phase-1-Verhalten).
        // Harte Schutzfilter bleiben davon unberuehrt.
        $this->RegisterPropertyBoolean("PolitikAktiv", true);
        $this->RegisterPropertyString("RuheVon", "");
        $this->RegisterPropertyString("RuheBis", "");
        $this->RegisterPropertyInteger("GlobalDndVarID", 0);

        $this->RegisterPropertyInteger("RetentionTage", 30);
        $this->RegisterPropertyInteger("MaxOffeneMeldungen", 2000);
        $this->RegisterPropertyInteger("SegmentMaxMB", 8);
        $this->RegisterPropertyInteger("LoeschKarenzSekunden", 300);
        $this->RegisterPropertyString("AlarmFallbackKanaele", "");

        // Rollierender Rate-Zustand je (Quelle, RateKey). Bewusst hier und
        // nicht im Journal: Das ist append-only, eine Schleife wuerde es
        // sonst mit einem Eintrag je unterdruecktem Vorfall fluten.
        $this->RegisterAttributeString("RateZustand", "{}");

        $this->RegisterVariableInteger("OffeneMeldungen", "Offene Meldungen", "", 10);
        $this->RegisterVariableString("Letzte", "Letzte Meldung", "", 20);
        $this->RegisterVariableString("Anzeige", "Meldungen", "~HTMLBox", 30);

        // EIN Timer auf den jeweils naechsten Faelligkeitszeitpunkt. Keine
        // Timer je Aktion - das erzeugte nur Objekt- und Zustandschurn.
        $this->RegisterTimer("Faellig", 0, 'MZ_Faellig($_IPS[\'TARGET\']);');
        $this->RegisterTimer("Aufraeumen", 0, 'MZ_Aufraeumen($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $this->VerzeichnisseAnlegen();
        $this->RegisterHook("/hook/meldungszentrale");

        $fehler = $this->KonfigurationPruefen();
        if ($fehler !== "") {
            $this->SetStatus(201);
            $this->SendDebug("Konfiguration", $fehler, 0);
        } elseif ($this->GetStatus() == 201) {
            $this->SetStatus(102);
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Recovery();
            $this->NachLaufAktualisieren();
            $this->SetTimerInterval("Aufraeumen", 15 * 60 * 1000);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != IPS_KERNELSTARTED) return;
        $this->Recovery();
        $this->NachLaufAktualisieren();
        $this->SetTimerInterval("Aufraeumen", 15 * 60 * 1000);
    }

    // ==================================================================
    // Annahme
    // ==================================================================

    /**
     * Meldung annehmen. Liefert IMMER ein strukturiertes Ergebnis - eine
     * nackte Zeichenkette koennte Erfolg, Fehler und Warnung nicht
     * unterscheiden.
     */
    public function Melden(string $Options): string {
        // Vollstaendig gekapselt: Ein unbehandelter Fehler darf nie dazu
        // fuehren, dass ein Alarm lautlos verschwindet.
        try {
            $ergebnis = $this->MeldenIntern($Options);
        } catch (\Throwable $e) {
            $this->SendDebug("Melden", "Unbehandelt: " . $e->getMessage(), 0);
            $opt = json_decode($Options, true);
            if (is_array($opt) && ($opt["dringlichkeit"] ?? "") === "alarm"
                && $this->QuelleGefahrenberechtigt((string)($opt["quelle"] ?? ""))) {
                $this->AlarmFallback($opt);
                $ergebnis = ["ok" => false, "fehlercode" => "intern", "alarmFallback" => true];
            } else {
                $ergebnis = ["ok" => false, "fehlercode" => "intern"];
            }
        }
        return json_encode($ergebnis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function MeldenIntern(string $Options): array {
        // --- 1. Rohdaten -------------------------------------------------
        $opt = json_decode($Options, true);
        if (!is_array($opt)) {
            // KEIN Rohdatenausschnitt: Defektes JSON kann trotzdem ein
            // Geheimnis oder einen Token enthalten, und ohne Parsing laesst
            // sich nichts zuverlaessig redigieren.
            $this->Annahmefehler("schema", $Options, "");
            return ["ok" => false, "fehlercode" => "schema"];
        }
        $quelle = trim((string)($opt["quelle"] ?? ""));
        if ($quelle === "") {
            $this->Annahmefehler("quelle_fehlt", $Options, "");
            return ["ok" => false, "fehlercode" => "schema"];
        }

        // --- 2. Quellenregistrierung -------------------------------------
        // Fuer lokale Quellen ist das eine Plausibilitaets-, keine
        // Sicherheitsgrenze: Jedes Symcon-Skript laeuft mit denselben
        // Rechten und koennte jede Kennung behaupten. Der Nutzen liegt in
        // Dringlichkeitsbegrenzung, Flutschutz und Nachvollziehbarkeit.
        $q = $this->QuelleFinden($quelle);
        if ($q === null) {
            $this->Annahmefehler("quelle_unbekannt", $Options, $quelle);
            return ["ok" => false, "fehlercode" => "quelle_unbekannt"];
        }

        $warnungen = [];
        $dring = strtolower(trim((string)($opt["dringlichkeit"] ?? "normal")));
        if (!isset(self::DRINGLICHKEIT[$dring])) $dring = "normal";

        // --- 3. Fachliche Validierung ------------------------------------
        $ereignis = trim((string)($opt["ereignis"] ?? ""));
        $titel    = trim((string)($opt["titel"] ?? ""));
        $text     = trim((string)($opt["text"] ?? ""));
        if ($ereignis === "" && $text === "") {
            return $this->Abweisung($quelle, "schema", "weder Ereignis noch Text");
        }
        if (trim($q["ErlaubteEreignisse"]) !== "" && $ereignis !== ""
            && !in_array($ereignis, $this->Liste($q["ErlaubteEreignisse"]))) {
            return $this->Abweisung($quelle, "ereignis_nicht_erlaubt", $ereignis);
        }

        // --- 4. Dringlichkeit --------------------------------------------
        // Unberechtigtes "alarm" ist ein Fehler, keine stille Kappung: Wer
        // sich faelschlich fuer alarmberechtigt haelt, soll das erfahren.
        if ($dring === "alarm" && !$q["Gefahrenberechtigt"]) {
            return $this->Abweisung($quelle, "alarm_nicht_erlaubt", $quelle);
        }
        $max = strtolower(trim((string)$q["MaxDringlichkeit"]));
        if (isset(self::DRINGLICHKEIT[$max]) && self::DRINGLICHKEIT[$dring] > self::DRINGLICHKEIT[$max]) {
            $dring = $max;
            $warnungen[] = "dringlichkeit_gekappt";
        }

        // Unbekannte Adressaten weichen NICHT still auf global aus - das
        // truege eine persoenliche Meldung ins ganze Haus.
        $zonen = $this->AlsListe($opt["zone"] ?? []);
        $empf  = $this->AlsListe($opt["empfaenger"] ?? []);
        foreach ($zonen as $z) {
            if ($this->ZoneFinden($z) === null) return $this->Abweisung($quelle, "unbekannte_zone", $z);
        }
        foreach ($empf as $e) {
            if ($this->EmpfaengerFinden($e) === null) return $this->Abweisung($quelle, "unbekannter_empfaenger", $e);
        }

        // Aktionen tragen nur Kennungen. Eine Skript-ID aus dem Aufruf waere
        // eine Ausfuehrungsbefugnis fuer beliebigen Code.
        $aktionen = [];
        foreach ($this->AlsListe($opt["aktionen"] ?? []) as $a) {
            if (!is_array($a)) continue;
            $kennung = trim((string)($a["aktionskennung"] ?? ""));
            $def = $this->AktionFinden($kennung);
            if ($def === null) return $this->Abweisung($quelle, "aktion_unbekannt", $kennung);
            if (trim($def["ErlaubteQuellen"]) !== "" && !in_array($quelle, $this->Liste($def["ErlaubteQuellen"]))) {
                return $this->Abweisung($quelle, "aktion_quelle_nicht_erlaubt", $kennung);
            }
            $aktionen[] = [
                "aktionskennung" => $kennung,
                "beschriftung"   => trim((string)($a["beschriftung"] ?? $kennung)),
                "gruppe"         => trim($def["Exklusivgruppe"]) !== "" ? trim($def["Exklusivgruppe"]) : "_" . $kennung,
                "zustand"        => self::AKTION_OFFEN,
                "tokens"         => [],
                "versuche"       => 0
            ];
        }

        $sek = (int)($opt["gueltigSekunden"] ?? 0);
        if (count($aktionen) > 0) {
            if ($sek <= 0) return $this->Abweisung($quelle, "aktion_ohne_gueltigkeit", "");
            foreach ($aktionen as $a) {
                $def = $this->AktionFinden($a["aktionskennung"]);
                $grenze = (int)$def["MaxGueltigSekunden"];
                if ($grenze > 0 && $sek > $grenze) { $sek = $grenze; $warnungen[] = "gueltigkeit_gekappt"; }
            }
        } elseif ($sek <= 0) {
            $sek = self::VORGABE_GUELTIG[$dring];
        }

        $vertraulich = (bool)($opt["vertraulich"] ?? false);
        $finger = $this->Fingerabdruck($quelle, $ereignis, $titel, $text, $dring, $vertraulich, $zonen, $empf, $aktionen);
        $dedupKey = trim((string)($opt["dedupKey"] ?? ""));

        // --- 5. DEDUP vor Flutschutz --------------------------------------
        // Ein Wiederholversuch nach verlorenem Rueckgabewert loest das
        // Idempotenzversprechen ein und ist kein Flutereignis.
        if ($dedupKey !== "") {
            $lock = "MZ_Dedup_" . md5($quelle . "|" . $dedupKey);
            if (!IPS_SemaphoreEnter($lock, self::LOCK_MS)) return ["ok" => false, "fehlercode" => "intern"];
            try {
                $vorhanden = $this->MeldungPerDedup($quelle, $dedupKey);
                if ($vorhanden !== null) {
                    if (($vorhanden["fingerabdruck"] ?? "") === $finger) {
                        return ["ok" => true, "meldungID" => $vorhanden["meldungID"],
                                "effektiveDringlichkeit" => $vorhanden["dringlichkeit"],
                                "warnungen" => ["dedup_treffer"], "fehlercode" => null];
                    }
                    return ["ok" => false, "fehlercode" => "dedup_konflikt", "meldungID" => $vorhanden["meldungID"]];
                }
                return $this->Anlegen($q, $quelle, $ereignis, $titel, $text, $dring, $vertraulich,
                                      $zonen, $empf, $aktionen, $sek, $dedupKey, $finger, $warnungen);
            } finally {
                IPS_SemaphoreLeave($lock);
            }
        }
        return $this->Anlegen($q, $quelle, $ereignis, $titel, $text, $dring, $vertraulich,
                              $zonen, $empf, $aktionen, $sek, "", $finger, $warnungen);
    }

    private function Anlegen(array $q, string $quelle, string $ereignis, string $titel, string $text,
                             string $dring, bool $vertraulich, array $zonen, array $empf,
                             array $aktionen, int $sek, string $dedupKey, string $finger,
                             array $warnungen): array {

        // --- 6. Flutschutz ------------------------------------------------
        // Der RateKey wird SERVERSEITIG gebildet. Ein im Aufruf mitgesandter
        // Wert wuerde den Schutz wertlos machen: Wer bei jedem Aufruf einen
        // neuen Schluessel waehlt, wird nie gedrosselt.
        $rateKey = $this->RateKeyBilden($q, $ereignis);
        $minIntervall = (int)$q["MinIntervallSekunden"];
        if ($minIntervall > 0 && $dring !== "alarm") {
            $lock = "MZ_Rate_" . md5($quelle . "|" . $rateKey);
            if (IPS_SemaphoreEnter($lock, self::LOCK_MS)) {
                try {
                    $zustand = json_decode($this->ReadAttributeString("RateZustand"), true);
                    if (!is_array($zustand)) $zustand = [];
                    $k = $quelle . "|" . $rateKey;
                    $eintrag = $zustand[$k] ?? ["letzte" => 0, "anzahl" => 0, "meldungID" => ""];
                    if ((time() - (int)$eintrag["letzte"]) < $minIntervall) {
                        $eintrag["anzahl"] = (int)$eintrag["anzahl"] + 1;
                        $eintrag["letzterVorfall"] = time();
                        $zustand[$k] = $eintrag;
                        $this->WriteAttributeString("RateZustand", json_encode($zustand));
                        // Kein Fehler: Die Quelle soll nicht in eine
                        // Wiederholschleife laufen.
                        return ["ok" => true, "aggregiert" => true,
                                "meldungID" => $eintrag["meldungID"],
                                "effektiveDringlichkeit" => $dring,
                                "warnungen" => array_merge($warnungen, ["gedrosselt"]),
                                "fehlercode" => null];
                    }
                } finally {
                    IPS_SemaphoreLeave($lock);
                }
            }
        }

        // --- 7. Identitaet -------------------------------------------------
        // Zufaellig statt fortlaufend: Ein gemeinsamer Zaehler waere erneut
        // geteilter Zustand mit eigener Race Condition.
        $meldungID = "m" . bin2hex(random_bytes(16));
        $jetzt = time();
        $meldung = [
            "meldungID"     => $meldungID,
            "revision"      => 1,
            "quelle"        => $quelle,
            "dedupKey"      => $dedupKey,
            "fingerabdruck" => $finger,
            "ereignis"      => $ereignis,
            "titel"         => $titel,
            "text"          => $text,
            "dringlichkeit" => $dring,
            "vertraulich"   => $vertraulich,
            "zone"          => $zonen,
            "empfaenger"    => $empf,
            "erstellt"      => $jetzt,
            "gueltigBis"    => $jetzt + $sek,
            "aktionen"      => $aktionen,
            "auftraege"     => [],
            "status"        => "angenommen"
        ];

        // --- 8. COMMIT -----------------------------------------------------
        $lock = $this->StoreLockName($meldungID);
        if (!IPS_SemaphoreEnter($lock, self::LOCK_MS)) return ["ok" => false, "fehlercode" => "intern"];
        try {
            if (!$this->MeldungSchreiben($meldung)) {
                // Vor dem Commit-Punkt: Die Meldung gilt als NICHT angenommen.
                if ($dring === "alarm" && $q["Gefahrenberechtigt"]) {
                    $this->AlarmFallback(["titel" => $titel, "text" => $text, "ereignis" => $ereignis,
                                          "vertraulich" => $vertraulich]);
                    return ["ok" => false, "fehlercode" => "speicher", "alarmFallback" => true];
                }
                return ["ok" => false, "fehlercode" => "speicher"];
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }

        // Ab hier ist die Meldung dauerhaft. Ein Journalfehler kann daran
        // nichts mehr aendern - er erzeugt nur eine Warnung (Variante A).
        if (!$this->JournalAnhaengen(["typ" => "commit", "meldungID" => $meldungID, "quelle" => $quelle,
                                      "ereignis" => $ereignis, "dringlichkeit" => $dring,
                                      "vertraulich" => $vertraulich, "revision" => 1])) {
            $warnungen[] = "audit_nachtrag_offen";
        }

        if ($dedupKey !== "" && $minIntervall > 0) $this->RateStempeln($quelle, $rateKey, $meldungID);

        // --- 9. Routing -----------------------------------------------------
        $this->Routen($meldungID);
        $this->NachLaufAktualisieren();

        return ["ok" => true, "meldungID" => $meldungID, "effektiveDringlichkeit" => $dring,
                "warnungen" => $warnungen, "fehlercode" => null];
    }

    // ==================================================================
    // Routing
    // ==================================================================

    private function Routen(string $meldungID) {
        $m = $this->MeldungLesen($meldungID);
        if ($m === null) return;

        $kandidaten = $this->Kandidaten($m);
        $ausgewaehlt = [];
        $abgelehnt = [];

        foreach ($kandidaten as $k) {
            $grund = $this->HarterFilter($m, $k);
            if ($grund !== "") { $abgelehnt[$k["Key"]] = $grund; continue; }

            if ($this->ReadPropertyBoolean("PolitikAktiv")) {
                $grund = $this->WeicherFilter($m, $k);
                if ($grund !== "") { $abgelehnt[$k["Key"]] = $grund; continue; }
            }
            $ausgewaehlt[] = $k;
        }

        $ausgewaehlt = $this->ZustellmodusAnwenden($ausgewaehlt);

        foreach ($abgelehnt as $key => $grund) {
            $this->JournalAnhaengen(["typ" => "gefiltert", "meldungID" => $meldungID,
                                     "kanal" => $key, "grund" => $grund]);
        }

        if (count($ausgewaehlt) === 0) {
            $this->JournalAnhaengen(["typ" => "kein_kanal", "meldungID" => $meldungID]);
            return;
        }

        $lock = $this->StoreLockName($meldungID);
        if (!IPS_SemaphoreEnter($lock, self::LOCK_MS)) return;
        try {
            $m = $this->MeldungLesen($meldungID);
            if ($m === null) return;
            foreach ($ausgewaehlt as $k) {
                $m["auftraege"][$k["Key"]] = [
                    "status"   => self::AUFTRAG_GEPLANT,
                    "versuche" => 0,
                    "geplant"  => time(),
                    "ackBis"   => time() + max(5, (int)$k["AckTimeoutSekunden"]),
                    "fehler"   => ""
                ];
            }
            $m["status"] = "geroutet";
            $this->MeldungSchreiben($m);
        } finally {
            IPS_SemaphoreLeave($lock);
        }

        foreach ($ausgewaehlt as $k) $this->AdapterAufrufen($meldungID, $k);
        $this->NachLaufAktualisieren();
    }

    /** Adressierungsmatrix aus Konzept 6.1. */
    private function Kandidaten(array $m): array {
        $out = [];
        $hatAdresse = count($m["zone"]) > 0 || count($m["empfaenger"]) > 0;
        foreach ($this->KanalListe() as $k) {
            if (!$k["Aktiv"]) continue;
            $passt = false;
            if ($k["Adressierung"] === "zone" && in_array($k["ZoneKey"], $m["zone"])) $passt = true;
            if ($k["Adressierung"] === "person" && in_array($k["EmpfaengerKey"], $m["empfaenger"])) $passt = true;
            if ($k["Adressierung"] === "global") {
                // Globale Kanaele kommen bei adressierten Meldungen nur dazu,
                // wenn sie "immer" sind (die Anzeige).
                $passt = !$hatAdresse || $k["Zustellmodus"] === "immer";
            }
            if ($passt) $out[] = $k;
        }
        return $out;
    }

    /**
     * Harte Schutzfilter. Werden NIEMALS uebersprungen - nicht bei
     * Fail-open, nicht bei PolitikAktiv = false, nicht im Alarm-Fallback.
     */
    private function HarterFilter(array $m, array $k): string {
        if (!$k["Aktiv"]) return "kanal_inaktiv";

        // Darstellbarkeit
        $kann = $this->Liste($k["Kann"]);
        $hatEreignis = $m["ereignis"] !== "" && in_array("ereignis", $kann);
        $hatText     = $m["text"] !== "" && in_array("text", $kann);
        if (!$hatEreignis && !$hatText) return "nicht_darstellbar";

        // Eine Meldung ohne Ereigniskennung darf nur an Kanaele ohne
        // Ereignisfilter - sonst wuerde der Filter stillschweigend ins Leere
        // laufen und alles durchlassen.
        $filter = $this->Liste($k["Ereignisfilter"]);
        if (count($filter) > 0) {
            if ($m["ereignis"] === "" || !in_array($m["ereignis"], $filter)) return "ereignisfilter";
        }

        if ($k["Zustellmodus"] === "nur_alarm" && $m["dringlichkeit"] !== "alarm") return "nur_alarm";

        // Vertraulichkeit: Ein Kanal muss nicht nur "nicht oeffentlich"
        // behaupten, sondern eine NACHGEWIESENE Empfaengerbindung haben.
        // In einer Symcon-Installation ohne Benutzeranmeldung erfuellt das
        // kein WebFront - solche Meldungen bleiben dann unzustellbar, und
        // genau das ist das sichere Verhalten.
        if ($m["vertraulich"]) {
            if ($k["Oeffentlich"]) return "vertraulich_oeffentlich";
            if (!$k["EmpfaengerBindungNachgewiesen"]) return "vertraulich_ohne_bindung";
            if (!in_array($k["EmpfaengerKey"], $m["empfaenger"])) return "vertraulich_falscher_empfaenger";
        }
        return "";
    }

    /**
     * Weiche Politikfilter. Bei Auswertungsfehlern und Dringlichkeit ab
     * "wichtig" wird NUR der fehlerhafte Filter uebersprungen - nicht die
     * ganze Kette und niemals ein harter Filter.
     */
    private function WeicherFilter(array $m, array $k): string {
        $failOpen = self::DRINGLICHKEIT[$m["dringlichkeit"]] >= self::DRINGLICHKEIT["wichtig"];

        $minD = strtolower(trim($k["MinDringlichkeit"]));
        if (isset(self::DRINGLICHKEIT[$minD])
            && self::DRINGLICHKEIT[$m["dringlichkeit"]] < self::DRINGLICHKEIT[$minD]) {
            return "unter_mindestdringlichkeit";
        }

        $dnd = false;
        $ruheVon = ""; $ruheBis = "";
        if ($k["Adressierung"] === "zone") {
            $z = $this->ZoneFinden($k["ZoneKey"]);
            if ($z !== null) {
                if ($z["PraesenzPflicht"]) {
                    $p = $this->SignalLesen((int)$z["PraesenzVarID"], $failOpen);
                    if ($p === null) { /* fail-open: Filter uebersprungen */ }
                    elseif (!$p) return "keine_praesenz";
                }
                $d = $this->SignalLesen((int)$z["DndVarID"], $failOpen);
                if ($d === true) $dnd = true;
                $ruheVon = $z["RuheVon"]; $ruheBis = $z["RuheBis"];
            }
        } elseif ($k["Adressierung"] === "person") {
            $e = $this->EmpfaengerFinden($k["EmpfaengerKey"]);
            if ($e !== null) {
                if (!$k["ZustelltExtern"]) {
                    $a = $this->SignalLesen((int)$e["AnwesendVarID"], $failOpen);
                    if ($a === false) return "nicht_anwesend";
                }
                $d = $this->SignalLesen((int)$e["DndVarID"], $failOpen);
                if ($d === true) $dnd = true;
                $ruheVon = $e["RuheVon"]; $ruheBis = $e["RuheBis"];
            }
        } else {
            $d = $this->SignalLesen($this->ReadPropertyInteger("GlobalDndVarID"), $failOpen);
            if ($d === true) $dnd = true;
        }

        // Nur ein Alarm bricht "nicht stoeren".
        if ($dnd && $m["dringlichkeit"] !== "alarm") return "dnd";

        if ($ruheVon === "" || $ruheBis === "") {
            $ruheVon = $this->ReadPropertyString("RuheVon");
            $ruheBis = $this->ReadPropertyString("RuheBis");
        }
        if ($this->IstRuhezeit($ruheVon, $ruheBis)) {
            $erlaubt = self::RUHE_DURCHGRIFF[$m["dringlichkeit"]];
            $stoer = self::STOERGRAD[strtolower(trim($k["Stoergrad"]))] ?? 0;
            if ($stoer > $erlaubt) return "ruhezeit";
        }
        return "";
    }

    /** ausweich-Kanaele springen nur ein, wenn ihre Gruppe leer blieb. */
    private function ZustellmodusAnwenden(array $kanaele): array {
        $bevorzugt = [];
        foreach ($kanaele as $k) {
            if ($k["Zustellmodus"] === "bevorzugt" || $k["Zustellmodus"] === "immer" || $k["Zustellmodus"] === "nur_alarm") {
                $bevorzugt[$k["FallbackGruppe"]] = true;
            }
        }
        $out = [];
        foreach ($kanaele as $k) {
            if ($k["Zustellmodus"] === "ausweich" && isset($bevorzugt[$k["FallbackGruppe"]])) continue;
            $out[] = $k;
        }
        usort($out, function ($a, $b) { return (int)$a["Prioritaet"] <=> (int)$b["Prioritaet"]; });
        return $out;
    }

    private function AdapterAufrufen(string $meldungID, array $k) {
        $m = $this->MeldungLesen($meldungID);
        if ($m === null) return;
        $sid = (int)$k["ScriptID"];
        if ($sid <= 0) {
            // Ein Kanal ohne Adapterskript ist der modulinterne Anzeigekanal:
            // Das Modul rendert die Liste selbst, es gibt nichts zuzustellen.
            // Ohne diesen Fall liefe die Anzeige in eine Retry-Schleife.
            $this->Zustellstatus($meldungID . "|" . $k["Key"], self::AUFTRAG_BESTAETIGT, "");
            return;
        }
        if (!IPS_ScriptExists($sid)) {
            $this->Zustellstatus($meldungID . "|" . $k["Key"], self::AUFTRAG_FEHLER, "kein_adapter");
            return;
        }

        // Aktionen bekommt nur, wer sie auch zurueckliefern kann. Ein Kanal
        // ohne Callback erhaelt die Meldung als reinen Text - er wird nicht
        // verworfen, ihm fehlt nur der Knopf.
        $kann = $this->Liste($k["Kann"]);
        $aktionen = [];
        if (in_array("aktion_anzeigen", $kann) && in_array("callback", $kann)) {
            foreach ($m["aktionen"] as $a) {
                if ($a["zustand"] !== self::AKTION_OFFEN) continue;
                $def = $this->AktionFinden($a["aktionskennung"]);
                if ($def === null) continue;
                if (trim($def["ErlaubteKanaele"]) !== "" && !in_array($k["Key"], $this->Liste($def["ErlaubteKanaele"]))) continue;
                if (trim($def["ErlaubteEmpfaenger"]) !== "" && $k["EmpfaengerKey"] !== ""
                    && !in_array($k["EmpfaengerKey"], $this->Liste($def["ErlaubteEmpfaenger"]))) continue;
                $token = bin2hex(random_bytes(24));
                $this->TokenHinterlegen($meldungID, $a["aktionskennung"], $k["Key"], $token, $m["gueltigBis"]);
                $aktionen[] = ["aktionskennung" => $a["aktionskennung"],
                               "beschriftung" => $a["beschriftung"], "token" => $token];
            }
        }
        if (count($m["aktionen"]) > 0 && count($aktionen) === 0) {
            $this->JournalAnhaengen(["typ" => "ohne_aktion", "meldungID" => $meldungID, "kanal" => $k["Key"]]);
        }

        @IPS_RunScriptEx($sid, [
            "MeldungID"        => $meldungID,
            "ZustellauftragID" => $meldungID . "|" . $k["Key"],
            "Kanal"            => $k["Key"],
            "Ereignis"         => $m["ereignis"],
            "Titel"            => $m["titel"],
            "Text"             => $m["text"],
            "Dringlichkeit"    => $m["dringlichkeit"],
            "GueltigBis"       => $m["gueltigBis"],
            "Aktionen"         => json_encode($aktionen)
        ]);
    }

    // ==================================================================
    // Zustellstatus, Aktionen, Rueckzug
    // ==================================================================

    public function Zustellstatus(string $ZustellauftragID, string $Status, string $Fehlercode): bool {
        $teile = explode("|", $ZustellauftragID, 2);
        if (count($teile) != 2) return false;
        list($meldungID, $kanal) = $teile;

        $lock = $this->StoreLockName($meldungID);
        if (!IPS_SemaphoreEnter($lock, self::LOCK_MS)) return false;
        try {
            $m = $this->MeldungLesen($meldungID);
            if ($m === null || !isset($m["auftraege"][$kanal])) return false;
            $m["auftraege"][$kanal]["status"] = $Status;
            $m["auftraege"][$kanal]["fehler"] = $Fehlercode;
            $this->MeldungSchreiben($m);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
        $this->JournalAnhaengen(["typ" => "zustellung", "meldungID" => $meldungID,
                                 "kanal" => $kanal, "status" => $Status, "fehler" => $Fehlercode]);
        if ($Status === self::AUFTRAG_FEHLER) $this->ZustellfehlerBehandeln($meldungID, $kanal);
        return true;
    }

    /**
     * Aktion ausloesen. Zwei Sperren mit verschiedenen Aufgaben: Die
     * Gruppensperre sichert die fachliche Exklusivitaet ("Oeffnen" und
     * "Ignorieren" duerfen nicht beide wirken), der Store-Lock die
     * Dateiintegritaet gegen jeden anderen Schreiber. Reihenfolge immer
     * erst Gruppe, dann Store - sonst drohen Deadlocks.
     */
    public function Ausloesen(string $MeldungID, string $Aktionskennung, string $Token): bool {
        $m = $this->MeldungLesen($MeldungID);
        if ($m === null) return false;

        $idx = null;
        foreach ($m["aktionen"] as $i => $a) if ($a["aktionskennung"] === $Aktionskennung) $idx = $i;
        if ($idx === null) return false;

        $kanal = $this->TokenPruefen($m, $idx, $Token);
        if ($kanal === null) {
            $this->JournalAnhaengen(["typ" => "aktion_abgewiesen", "meldungID" => $MeldungID,
                                     "aktion" => $Aktionskennung, "grund" => "token"]);
            return false;
        }

        $gruppe = $m["aktionen"][$idx]["gruppe"];
        $gLock = "MZ_" . $MeldungID . "_" . $gruppe;
        if (!IPS_SemaphoreEnter($gLock, self::LOCK_MS)) return false;
        try {
            $sLock = $this->StoreLockName($MeldungID);
            if (!IPS_SemaphoreEnter($sLock, self::LOCK_MS)) return false;
            try {
                $m = $this->MeldungLesen($MeldungID);
                if ($m === null) return false;
                // Eine andere Aktion derselben Gruppe darf nicht schon laufen.
                foreach ($m["aktionen"] as $a) {
                    if ($a["gruppe"] === $gruppe && $a["zustand"] !== self::AKTION_OFFEN
                        && $a["zustand"] !== self::AKTION_ABGELAUFEN) {
                        return false;
                    }
                }
                if (time() > $m["gueltigBis"]) {
                    $m["aktionen"][$idx]["zustand"] = self::AKTION_ABGELAUFEN;
                    $this->MeldungSchreiben($m);
                    return false;
                }
                $m["aktionen"][$idx]["zustand"] = self::AKTION_LAEUFT;
                $m["aktionen"][$idx]["versuche"] = (int)$m["aktionen"][$idx]["versuche"] + 1;
                $this->MeldungSchreiben($m);
            } finally {
                IPS_SemaphoreLeave($sLock);
            }

            // Das Aktionsskript laeuft bewusst OHNE Store-Lock - es kann
            // beliebig lange dauern und wuerde sonst alles blockieren.
            $def = $this->AktionFinden($Aktionskennung);
            $erfolg = false;
            if ($def !== null && (int)$def["ScriptID"] > 0 && IPS_ScriptExists((int)$def["ScriptID"])) {
                try {
                    $r = IPS_RunScriptWaitEx((int)$def["ScriptID"],
                                             ["MeldungID" => $MeldungID, "Aktion" => $Aktionskennung]);
                    $erfolg = (trim((string)$r) !== "FEHLER");
                } catch (\Throwable $e) {
                    $erfolg = false;
                }
            }

            $sLock = $this->StoreLockName($MeldungID);
            if (IPS_SemaphoreEnter($sLock, self::LOCK_MS)) {
                try {
                    $m = $this->MeldungLesen($MeldungID);
                    if ($m !== null) {
                        if ($erfolg) {
                            $m["aktionen"][$idx]["zustand"] = self::AKTION_ERFOLG;
                            $m["aktionen"][$idx]["tokens"] = [];
                            // Nur Alternativen DERSELBEN Gruppe beenden.
                            foreach ($m["aktionen"] as $i => $a) {
                                if ($i != $idx && $a["gruppe"] === $gruppe) {
                                    $m["aktionen"][$i]["zustand"] = self::AKTION_ABGELAUFEN;
                                    $m["aktionen"][$i]["tokens"] = [];
                                }
                            }
                        } else {
                            $retry = $def !== null && $def["RetryBeiSicheremFehler"];
                            // Token bleibt gueltig, sonst waere ein Retry
                            // durch den Anwender gar nicht moeglich.
                            $m["aktionen"][$idx]["zustand"] = $retry ? self::AKTION_OFFEN : self::AKTION_FEHLER;
                        }
                        $this->MeldungSchreiben($m);
                    }
                } finally {
                    IPS_SemaphoreLeave($sLock);
                }
            }
        } finally {
            IPS_SemaphoreLeave($gLock);
        }

        $this->JournalAnhaengen(["typ" => "aktion", "meldungID" => $MeldungID, "aktion" => $Aktionskennung,
                                 "kanal" => $kanal, "ergebnis" => $erfolg ? "erfolgreich" : "fehlgeschlagen"]);
        if ($erfolg) $this->AktionZurueckziehen($MeldungID);
        $this->NachLaufAktualisieren();
        return $erfolg;
    }

    public function Zurueckziehen(string $MeldungID): bool {
        $lock = $this->StoreLockName($MeldungID);
        if (!IPS_SemaphoreEnter($lock, self::LOCK_MS)) return false;
        try {
            $m = $this->MeldungLesen($MeldungID);
            if ($m === null) return false;
            $m["status"] = "zurueckgezogen";
            $this->MeldungSchreiben($m);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
        $this->AktionZurueckziehen($MeldungID);
        $this->MeldungAbschliessen($MeldungID, "zurueckgezogen");
        return true;
    }

    public function Journal(string $Filter): string {
        $zeilen = [];
        foreach ($this->JournalSegmente() as $datei) {
            $fh = @fopen($datei, "r");
            if ($fh === false) continue;
            while (($z = fgets($fh)) !== false) {
                $z = trim($z);
                if ($z === "") continue;
                if ($Filter !== "" && stripos($z, $Filter) === false) continue;
                $d = json_decode($z, true);
                if (is_array($d)) $zeilen[] = $d;
            }
            fclose($fh);
        }
        return json_encode($zeilen, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Gueltige Meldungen. INTERNE Funktion - der Empfaengerschluessel ist
     * hier ein Filter, keine Autorisierung. Ein HTTP-Endpunkt muss den
     * Empfaenger serverseitig aus einer authentifizierten Identitaet
     * ableiten, nie aus einem Parameter des Aufrufers.
     */
    public function Aktuell(string $EmpfaengerKey): string {
        $out = [];
        foreach ($this->OffeneMeldungen() as $m) {
            if (time() > $m["gueltigBis"]) continue;
            if ($EmpfaengerKey !== "" && !in_array($EmpfaengerKey, $m["empfaenger"])) continue;
            // Vertrauliches erscheint nur bei ausdruecklicher Abfrage des
            // betroffenen Empfaengers, nie in einer Sammelansicht.
            if ($m["vertraulich"] && $EmpfaengerKey === "") continue;
            $out[] = ["meldungID" => $m["meldungID"], "titel" => $m["titel"], "text" => $m["text"],
                      "dringlichkeit" => $m["dringlichkeit"], "erstellt" => $m["erstellt"],
                      "gueltigBis" => $m["gueltigBis"]];
        }
        return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ==================================================================
    // Timer
    // ==================================================================

    public function Faellig() {
        $jetzt = time();
        foreach ($this->OffeneMeldungen() as $m) {
            if ($jetzt > $m["gueltigBis"]) {
                $this->MeldungAbschliessen($m["meldungID"], "abgelaufen");
                continue;
            }
            foreach ($m["auftraege"] as $kanal => $a) {
                if ($a["status"] === self::AUFTRAG_GEPLANT && $jetzt > (int)$a["ackBis"]) {
                    // Keine Quittung im Zeitfenster gilt als Fehlschlag.
                    $this->Zustellstatus($m["meldungID"] . "|" . $kanal, self::AUFTRAG_FEHLER, "timeout");
                }
            }
        }
        $this->NachLaufAktualisieren();
    }

    public function Aufraeumen() {
        $karenz = $this->ReadPropertyInteger("LoeschKarenzSekunden");
        $jetzt = time();
        foreach ($this->OffeneMeldungen() as $m) {
            if ($jetzt > $m["gueltigBis"] + $karenz) {
                $this->MeldungAbschliessen($m["meldungID"], "abgelaufen");
            }
        }
        $this->JournalRotieren();
        $this->RateZusammenfassen();
        $this->NachLaufAktualisieren();
    }

    /** Setzt den einen Timer auf den naechsten faelligen Zeitpunkt. */
    private function NachLaufAktualisieren() {
        $naechster = 0;
        $anzahl = 0;
        foreach ($this->OffeneMeldungen() as $m) {
            $anzahl++;
            $kandidat = (int)$m["gueltigBis"];
            foreach ($m["auftraege"] as $a) {
                if ($a["status"] === self::AUFTRAG_GEPLANT) $kandidat = min($kandidat, (int)$a["ackBis"]);
            }
            if ($naechster === 0 || $kandidat < $naechster) $naechster = $kandidat;
        }
        $this->SetValue("OffeneMeldungen", $anzahl);
        $this->AnzeigeRendern();

        if ($naechster === 0) { $this->SetTimerInterval("Faellig", 0); return; }
        $sek = max(1, $naechster - time() + 1);
        $this->SetTimerInterval("Faellig", $sek * 1000);
    }

    // ==================================================================
    // WebHook - zustandsaendernd ausschliesslich per POST
    // ==================================================================

    protected function ProcessHookData() {
        $methode = $_SERVER["REQUEST_METHOD"] ?? "GET";

        if ($methode !== "POST") {
            // GET aendert grundsaetzlich nichts: Browser-Prefetch,
            // Linkscanner und Vorschaufunktionen duerfen keine Tuer oeffnen.
            header("Content-Type: text/html; charset=utf-8");
            echo "<!doctype html><meta charset=\"utf-8\"><p>Meldungszentrale. Aktionen nur per POST.</p>";
            return;
        }

        // Token kommt im Body, nie in der URL - sonst landet es in
        // Browserverlauf, Proxy-Logs und Referrer.
        $roh = file_get_contents("php://input");
        $d = json_decode($roh, true);
        if (!is_array($d)) {
            parse_str($roh, $d);
            if (!is_array($d)) $d = [];
        }
        $meldungID = trim((string)($d["meldungID"] ?? ""));
        $aktion    = trim((string)($d["aktion"] ?? ""));
        $token     = trim((string)($d["token"] ?? ""));

        header("Content-Type: application/json; charset=utf-8");
        if ($meldungID === "" || $aktion === "" || $token === "") {
            http_response_code(400);
            echo json_encode(["ok" => false, "fehlercode" => "schema"]);
            return;
        }
        $ok = $this->Ausloesen($meldungID, $aktion, $token);
        if (!$ok) http_response_code(409);
        echo json_encode(["ok" => $ok]);
    }

    // ==================================================================
    // Persistenz
    // ==================================================================

    private function Basisverzeichnis(): string {
        return IPS_GetKernelDir() . "meldungszentrale/" . $this->InstanceID . "/";
    }
    private function OffenDir(): string  { return $this->Basisverzeichnis() . "offen/"; }
    private function JournalDir(): string { return $this->Basisverzeichnis() . "journal/"; }
    private function QuarantaeneDir(): string { return $this->Basisverzeichnis() . "quarantaene/"; }

    private function VerzeichnisseAnlegen() {
        foreach ([$this->OffenDir(), $this->JournalDir(), $this->QuarantaeneDir()] as $d) {
            if (!is_dir($d)) @mkdir($d, 0770, true);
        }
    }

    private function StoreLockName(string $meldungID): string {
        return "MZ_M_" . $this->InstanceID . "_" . $meldungID;
    }

    private function MeldungPfad(string $meldungID): string {
        // Pfadsicher: Die ID ist selbst erzeugt und hexadezimal, ein
        // zusaetzlicher Filter kostet nichts und schliesst Traversal aus.
        $sicher = preg_replace("/[^A-Za-z0-9_-]/", "", $meldungID);
        return $this->OffenDir() . $sicher . ".json";
    }

    private function MeldungLesen(string $meldungID): ?array {
        $p = $this->MeldungPfad($meldungID);
        if (!is_file($p)) return null;
        $d = json_decode(@file_get_contents($p), true);
        if (!is_array($d)) {
            @rename($p, $this->QuarantaeneDir() . basename($p));
            $this->JournalAnhaengen(["typ" => "quarantaene", "meldungID" => $meldungID]);
            return null;
        }
        return $d;
    }

    /**
     * Schreibprotokoll mit benanntem Commit-Punkt.
     *
     * Die temporaere Datei liegt zwingend IM Zielverzeichnis - sonst waere
     * rename() ein Verschieben ueber Dateisystemgrenzen und nicht atomar.
     * Nach dem rename wird zusaetzlich das Verzeichnis synchronisiert:
     * fsync auf die Datei sichert deren Inhalt, aber nicht den neuen
     * Verzeichniseintrag.
     *
     * Der Aufrufer MUSS den Store-Lock halten.
     */
    private function MeldungSchreiben(array $meldung): bool {
        $meldung["revision"] = (int)($meldung["revision"] ?? 0) + 1;
        $ziel = $this->MeldungPfad($meldung["meldungID"]);
        $tmp = $this->OffenDir() . ".tmp_" . bin2hex(random_bytes(6));

        $fh = @fopen($tmp, "w");
        if ($fh === false) return false;
        $ok = (@fwrite($fh, json_encode($meldung, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false);
        if ($ok) { @fflush($fh); @fsync($fh); }
        @fclose($fh);
        if (!$ok) { @unlink($tmp); return false; }

        if (!@rename($tmp, $ziel)) { @unlink($tmp); return false; }

        $dh = @fopen($this->OffenDir(), "r");
        if ($dh !== false) { @fsync($dh); @fclose($dh); }
        return true;
    }

    private function OffeneMeldungen(): array {
        $out = [];
        foreach (glob($this->OffenDir() . "*.json") as $p) {
            $d = json_decode(@file_get_contents($p), true);
            if (is_array($d)) $out[] = $d;
        }
        return $out;
    }

    private function MeldungPerDedup(string $quelle, string $dedupKey): ?array {
        foreach ($this->OffeneMeldungen() as $m) {
            if ($m["quelle"] === $quelle && ($m["dedupKey"] ?? "") === $dedupKey
                && time() <= $m["gueltigBis"]) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Meldung regulaer beenden. Der Tombstone ist Pflicht: Ohne ihn saehe
     * beim Neustart JEDE planmaessig geraeumte Meldung wie Datenverlust aus,
     * weil im Journal nur ihr Commit stuende.
     */
    private function MeldungAbschliessen(string $meldungID, string $grund) {
        $p = $this->MeldungPfad($meldungID);
        if (is_file($p)) @unlink($p);
        $this->JournalAnhaengen(["typ" => "geloescht", "meldungID" => $meldungID, "grund" => $grund]);
    }

    // ==================================================================
    // Journal
    // ==================================================================

    private function JournalPfad(): string {
        $tag = date("Y-m-d");
        $max = max(1, $this->ReadPropertyInteger("SegmentMaxMB")) * 1024 * 1024;
        $lfd = 0;
        while (true) {
            $p = $this->JournalDir() . $tag . "-" . sprintf("%03d", $lfd) . ".jsonl";
            if (!is_file($p) || filesize($p) < $max) return $p;
            $lfd++;
            if ($lfd > 999) return $p;
        }
    }

    private function JournalAnhaengen(array $eintrag): bool {
        $eintrag["zeit"] = time();
        // Vertraulicher Nutzinhalt und Tokens gehoeren nie ins Audit-Log.
        unset($eintrag["text"], $eintrag["token"]);
        $lock = "MZ_Journal_" . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, self::LOCK_MS)) return false;
        try {
            $fh = @fopen($this->JournalPfad(), "a");
            if ($fh === false) return false;
            $ok = (@fwrite($fh, json_encode($eintrag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") !== false);
            if ($ok) { @fflush($fh); @fsync($fh); }
            @fclose($fh);
            return $ok;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function JournalSegmente(): array {
        $f = glob($this->JournalDir() . "*.jsonl");
        sort($f);
        return $f;
    }

    private function JournalRotieren() {
        $grenze = time() - max(1, $this->ReadPropertyInteger("RetentionTage")) * 86400;
        foreach ($this->JournalSegmente() as $p) {
            if (@filemtime($p) < $grenze) @unlink($p);
        }
    }

    private function Annahmefehler(string $code, string $roh, string $quelle) {
        // Bewusst nur Hash und Laenge: Nicht parsebare Daten koennen ein
        // Geheimnis enthalten, das sich ohne Parsing nicht redigieren laesst.
        $this->JournalAnhaengen(["typ" => "annahmefehler", "fehlercode" => $code, "quelle" => $quelle,
                                 "laenge" => strlen($roh), "hash" => hash("sha256", $roh)]);
        IPS_LogMessage("Meldungszentrale", "Annahme abgelehnt: " . $code);
    }

    private function Abweisung(string $quelle, string $code, string $detail): array {
        $this->JournalAnhaengen(["typ" => "abgewiesen", "quelle" => $quelle,
                                 "fehlercode" => $code, "detail" => $detail]);
        IPS_LogMessage("Meldungszentrale", "Abgewiesen (" . $code . "): " . $detail);
        return ["ok" => false, "fehlercode" => $code];
    }

    // ==================================================================
    // Recovery
    // ==================================================================

    private function Recovery() {
        // Temporaerdateien stammen von einem Absturz VOR dem Commit-Punkt -
        // diese Meldungen galten nie als angenommen.
        foreach (glob($this->OffenDir() . ".tmp_*") as $p) @unlink($p);

        $committed = [];
        $beendet = [];
        foreach ($this->JournalSegmente() as $datei) {
            $fh = @fopen($datei, "r");
            if ($fh === false) continue;
            while (($z = fgets($fh)) !== false) {
                // Eine unvollstaendige letzte Zeile entsteht bei einem
                // Absturz mitten im Append - sie wird still verworfen, die
                // vorherigen Eintraege bleiben lesbar.
                if (substr($z, -1) !== "\n") continue;
                $d = json_decode(trim($z), true);
                if (!is_array($d) || !isset($d["meldungID"])) continue;
                if (($d["typ"] ?? "") === "commit") $committed[$d["meldungID"]] = true;
                if (($d["typ"] ?? "") === "geloescht" || ($d["typ"] ?? "") === "quarantaene") {
                    $beendet[$d["meldungID"]] = true;
                }
            }
            fclose($fh);
        }

        $vorhanden = [];
        foreach ($this->OffeneMeldungen() as $m) {
            $id = $m["meldungID"];
            $vorhanden[$id] = true;

            // Datei da, aber kein Commit im Journal: Absturz zwischen
            // Commit-Punkt und Journal-Append. Die Meldung ist gueltig,
            // der Audit-Eintrag wird nachgetragen.
            if (!isset($committed[$id])) {
                $this->JournalAnhaengen(["typ" => "commit", "meldungID" => $id, "quelle" => $m["quelle"],
                                         "nachgetragen" => true]);
            }

            $geaendert = false;
            foreach ($m["aktionen"] as $i => $a) {
                if ($a["zustand"] === self::AKTION_LAEUFT) {
                    // Das Skript lief, das Ergebnis ging verloren. Aus diesem
                    // Zustand wird NIE automatisch wiederholt - bei einer
                    // Tuer waere das eine zweite Oeffnung.
                    $m["aktionen"][$i]["zustand"] = self::AKTION_UNBEKANNT;
                    $geaendert = true;
                }
            }
            foreach ($m["auftraege"] as $kanal => $a) {
                if ($a["status"] === self::AUFTRAG_GEPLANT && time() <= $m["gueltigBis"]) {
                    $m["auftraege"][$kanal]["ackBis"] = time() + 60;
                    $geaendert = true;
                }
            }
            if ($geaendert) {
                $lock = $this->StoreLockName($id);
                if (IPS_SemaphoreEnter($lock, self::LOCK_MS)) {
                    try { $this->MeldungSchreiben($m); } finally { IPS_SemaphoreLeave($lock); }
                }
            }
        }

        // Commit ohne Datei UND ohne Abschlussdatensatz ist ein echter
        // Verlustverdacht - regulaer geraeumte Meldungen haben einen
        // Tombstone und tauchen hier nicht auf.
        foreach (array_keys($committed) as $id) {
            if (!isset($vorhanden[$id]) && !isset($beendet[$id])) {
                $this->JournalAnhaengen(["typ" => "verlustverdacht", "meldungID" => $id]);
                IPS_LogMessage("Meldungszentrale", "Verlustverdacht: " . $id);
            }
        }
    }

    // ==================================================================
    // Tokens und Rueckzug
    // ==================================================================

    private function TokenHinterlegen(string $meldungID, string $aktion, string $kanal,
                                      string $token, int $gueltigBis) {
        $lock = $this->StoreLockName($meldungID);
        if (!IPS_SemaphoreEnter($lock, self::LOCK_MS)) return;
        try {
            $m = $this->MeldungLesen($meldungID);
            if ($m === null) return;
            foreach ($m["aktionen"] as $i => $a) {
                if ($a["aktionskennung"] !== $aktion) continue;
                // Nur der Hash wird gespeichert - ein Datenleck der
                // Meldungsdatei gibt so kein einloesbares Token preis.
                $m["aktionen"][$i]["tokens"][$kanal] = ["hash" => hash("sha256", $token),
                                                        "ablauf" => $gueltigBis];
            }
            $this->MeldungSchreiben($m);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function TokenPruefen(array $m, int $idx, string $token): ?string {
        $hash = hash("sha256", $token);
        foreach (($m["aktionen"][$idx]["tokens"] ?? []) as $kanal => $t) {
            if (hash_equals((string)$t["hash"], $hash) && time() <= (int)$t["ablauf"]) return $kanal;
        }
        return null;
    }

    /** Best-Effort: Der Knopf soll verschwinden, wenn er nichts mehr tut. */
    private function AktionZurueckziehen(string $meldungID) {
        $m = $this->MeldungLesen($meldungID);
        if ($m === null) return;
        foreach ($m["auftraege"] as $kanal => $a) {
            $k = $this->KanalFinden($kanal);
            if ($k === null || !in_array("zurueckziehen", $this->Liste($k["Kann"]))) continue;
            $sid = (int)$k["ScriptID"];
            if ($sid > 0 && IPS_ScriptExists($sid)) {
                @IPS_RunScriptEx($sid, ["MeldungID" => $meldungID, "Kanal" => $kanal, "Ruecknahme" => true]);
            }
        }
    }

    private function ZustellfehlerBehandeln(string $meldungID, string $kanal) {
        $m = $this->MeldungLesen($meldungID);
        $k = $this->KanalFinden($kanal);
        if ($m === null || $k === null) return;

        // Fluechtige Kanaele werden nicht wiederholt: Eine verspaetete
        // Ansage stiftet mehr Verwirrung als Nutzen.
        if ($k["Lebensdauer"] !== "persistent") {
            $this->AusweichKanalBeauftragen($meldungID, $k);
            return;
        }
        $versuche = (int)($m["auftraege"][$kanal]["versuche"] ?? 0);
        if ($versuche >= (int)$k["MaxVersuche"] || time() > $m["gueltigBis"]) {
            $lock = $this->StoreLockName($meldungID);
            if (IPS_SemaphoreEnter($lock, self::LOCK_MS)) {
                try {
                    $m = $this->MeldungLesen($meldungID);
                    if ($m !== null) {
                        $m["auftraege"][$kanal]["status"] = self::AUFTRAG_AUFGEGEBEN;
                        $this->MeldungSchreiben($m);
                    }
                } finally { IPS_SemaphoreLeave($lock); }
            }
            $this->AusweichKanalBeauftragen($meldungID, $k);
            return;
        }
        $lock = $this->StoreLockName($meldungID);
        if (IPS_SemaphoreEnter($lock, self::LOCK_MS)) {
            try {
                $m = $this->MeldungLesen($meldungID);
                if ($m !== null) {
                    $abstand = self::RETRY_ABSTAENDE[min($versuche, count(self::RETRY_ABSTAENDE) - 1)];
                    $m["auftraege"][$kanal]["status"] = self::AUFTRAG_GEPLANT;
                    $m["auftraege"][$kanal]["versuche"] = $versuche + 1;
                    $m["auftraege"][$kanal]["ackBis"] = time() + $abstand + (int)$k["AckTimeoutSekunden"];
                    $this->MeldungSchreiben($m);
                }
            } finally { IPS_SemaphoreLeave($lock); }
        }
        $this->AdapterAufrufen($meldungID, $k);
    }

    private function AusweichKanalBeauftragen(string $meldungID, array $gescheitert) {
        $gruppe = $gescheitert["FallbackGruppe"];
        if ($gruppe === "") return;
        $m = $this->MeldungLesen($meldungID);
        if ($m === null) return;
        foreach ($this->KanalListe() as $k) {
            if ($k["FallbackGruppe"] !== $gruppe || $k["Zustellmodus"] !== "ausweich" || !$k["Aktiv"]) continue;
            if (isset($m["auftraege"][$k["Key"]])) continue;
            if ($this->HarterFilter($m, $k) !== "") continue;
            $lock = $this->StoreLockName($meldungID);
            if (IPS_SemaphoreEnter($lock, self::LOCK_MS)) {
                try {
                    $m = $this->MeldungLesen($meldungID);
                    if ($m !== null) {
                        $m["auftraege"][$k["Key"]] = ["status" => self::AUFTRAG_GEPLANT, "versuche" => 0,
                                                      "geplant" => time(),
                                                      "ackBis" => time() + max(5, (int)$k["AckTimeoutSekunden"]),
                                                      "fehler" => ""];
                        $this->MeldungSchreiben($m);
                    }
                } finally { IPS_SemaphoreLeave($lock); }
            }
            $this->JournalAnhaengen(["typ" => "retry_eskalation", "meldungID" => $meldungID,
                                     "von" => $gescheitert["Key"], "nach" => $k["Key"]]);
            $this->AdapterAufrufen($meldungID, $k);
            return;
        }
    }

    /**
     * Letzte Linie, wenn Speichern oder Routing scheitert. Bewusst nicht
     * "Notpfad" genannt: Gegen einen Ausfall des Moduls selbst schuetzt er
     * nicht - dafuer stehen autarke Hardware-Sirenen.
     */
    private function AlarmFallback(array $opt) {
        $keys = $this->Liste($this->ReadPropertyString("AlarmFallbackKanaele"));
        $vertraulich = (bool)($opt["vertraulich"] ?? false);
        foreach ($keys as $key) {
            $k = $this->KanalFinden($key);
            if ($k === null || !$k["Aktiv"]) continue;
            // Auch hier gilt die Vertraulichkeit - ein Notfall ist kein
            // Grund, private Inhalte ins ganze Haus zu rufen.
            if ($vertraulich && $k["Oeffentlich"]) continue;
            $sid = (int)$k["ScriptID"];
            if ($sid <= 0 || !IPS_ScriptExists($sid)) continue;
            // Ohne Aktionen und ohne Token.
            @IPS_RunScriptEx($sid, ["MeldungID" => "", "ZustellauftragID" => "", "Kanal" => $key,
                                    "Ereignis" => (string)($opt["ereignis"] ?? ""),
                                    "Titel" => (string)($opt["titel"] ?? "Alarm"),
                                    "Text" => (string)($opt["text"] ?? ""),
                                    "Dringlichkeit" => "alarm", "GueltigBis" => time() + 300,
                                    "Aktionen" => "[]"]);
        }
        $this->JournalAnhaengen(["typ" => "alarm_fallback", "kanaele" => implode(",", $keys)]);
    }

    // ==================================================================
    // Anzeige
    // ==================================================================

    private function AnzeigeRendern() {
        $zeilen = [];
        foreach ($this->OffeneMeldungen() as $m) {
            if (time() > $m["gueltigBis"]) continue;
            // Die oeffentliche Anzeige zeigt niemals vertrauliche Inhalte.
            if ($m["vertraulich"]) continue;
            $zeilen[] = $m;
        }
        usort($zeilen, function ($a, $b) { return $b["erstellt"] <=> $a["erstellt"]; });

        $h = "<style>.mz{font-family:sans-serif}.mz div{padding:4px 0;border-bottom:1px solid #ddd}"
           . ".mz .a{color:#c00;font-weight:bold}</style><div class=\"mz\">";
        if (count($zeilen) === 0) {
            $h .= "<div>Keine offenen Meldungen.</div>";
        }
        foreach ($zeilen as $m) {
            $klasse = $m["dringlichkeit"] === "alarm" || $m["dringlichkeit"] === "wichtig" ? " class=\"a\"" : "";
            $h .= "<div><span" . $klasse . ">" . htmlspecialchars($m["titel"] !== "" ? $m["titel"] : $m["ereignis"])
                . "</span> " . htmlspecialchars($m["text"])
                . " <small>(" . date("d.m. H:i", $m["erstellt"]) . ")</small></div>";
        }
        $h .= "</div>";
        $this->SetValue("Anzeige", $h);
        if (count($zeilen) > 0) {
            $this->SetValue("Letzte", $zeilen[0]["titel"] . " " . $zeilen[0]["text"]);
        }
    }

    // ==================================================================
    // Konfiguration
    // ==================================================================

    private function ListeAus(string $property): array {
        $d = json_decode($this->ReadPropertyString($property), true);
        return is_array($d) ? $d : [];
    }

    private function KanalListe(): array {
        $out = [];
        foreach ($this->ListeAus("Kanaele") as $k) {
            $out[] = [
                "Key"           => (string)($k["Key"] ?? ""),
                "Adressierung"  => (string)($k["Adressierung"] ?? "global"),
                "ZoneKey"       => (string)($k["ZoneKey"] ?? ""),
                "EmpfaengerKey" => (string)($k["EmpfaengerKey"] ?? ""),
                "Oeffentlich"   => (bool)($k["Oeffentlich"] ?? true),
                "EmpfaengerBindungNachgewiesen" => (bool)($k["EmpfaengerBindungNachgewiesen"] ?? false),
                "Stoergrad"     => (string)($k["Stoergrad"] ?? "keiner"),
                "MinDringlichkeit" => (string)($k["MinDringlichkeit"] ?? "info"),
                "Prioritaet"    => (int)($k["Prioritaet"] ?? 1),
                "FallbackGruppe" => (string)($k["FallbackGruppe"] ?? ""),
                "Zustellmodus"  => (string)($k["Zustellmodus"] ?? "bevorzugt"),
                "Ereignisfilter" => (string)($k["Ereignisfilter"] ?? ""),
                "ZustelltExtern" => (bool)($k["ZustelltExtern"] ?? false),
                "Kann"          => (string)($k["Kann"] ?? "text"),
                "Erfolgsendpunkt" => (string)($k["Erfolgsendpunkt"] ?? "adapter_angenommen"),
                "AckTimeoutSekunden" => (int)($k["AckTimeoutSekunden"] ?? 30),
                "MaxVersuche"   => (int)($k["MaxVersuche"] ?? 3),
                "Lebensdauer"   => (string)($k["Lebensdauer"] ?? "fluechtig"),
                "ScriptID"      => (int)($k["ScriptID"] ?? 0),
                "Aktiv"         => (bool)($k["Aktiv"] ?? true)
            ];
        }
        return $out;
    }

    private function KanalFinden(string $key): ?array {
        foreach ($this->KanalListe() as $k) if ($k["Key"] === $key) return $k;
        return null;
    }

    private function ZoneFinden(string $key): ?array {
        foreach ($this->ListeAus("Zonen") as $z) {
            if ((string)($z["Key"] ?? "") !== $key) continue;
            return ["Key" => $key, "PraesenzVarID" => (int)($z["PraesenzVarID"] ?? 0),
                    "PraesenzPflicht" => (bool)($z["PraesenzPflicht"] ?? true),
                    "DndVarID" => (int)($z["DndVarID"] ?? 0),
                    "RuheVon" => (string)($z["RuheVon"] ?? ""), "RuheBis" => (string)($z["RuheBis"] ?? ""),
                    "Aktiv" => (bool)($z["Aktiv"] ?? true)];
        }
        return null;
    }

    private function EmpfaengerFinden(string $key): ?array {
        foreach ($this->ListeAus("Empfaenger") as $e) {
            if ((string)($e["Key"] ?? "") !== $key) continue;
            return ["Key" => $key, "AnwesendVarID" => (int)($e["AnwesendVarID"] ?? 0),
                    "DndVarID" => (int)($e["DndVarID"] ?? 0),
                    "RuheVon" => (string)($e["RuheVon"] ?? ""), "RuheBis" => (string)($e["RuheBis"] ?? ""),
                    "Aktiv" => (bool)($e["Aktiv"] ?? true)];
        }
        return null;
    }

    private function QuelleFinden(string $key): ?array {
        foreach ($this->ListeAus("Quellen") as $q) {
            if ((string)($q["Quellkennung"] ?? "") !== $key) continue;
            if (!(bool)($q["Aktiv"] ?? true)) return null;
            return ["Quellkennung" => $key, "Art" => (string)($q["Art"] ?? "lokal"),
                    "ErlaubteEreignisse" => (string)($q["ErlaubteEreignisse"] ?? ""),
                    "MaxDringlichkeit" => (string)($q["MaxDringlichkeit"] ?? "wichtig"),
                    "Gefahrenberechtigt" => (bool)($q["Gefahrenberechtigt"] ?? false),
                    "RateKeyRegel" => (string)($q["RateKeyRegel"] ?? ""),
                    "MinIntervallSekunden" => (int)($q["MinIntervallSekunden"] ?? 0)];
        }
        return null;
    }

    private function QuelleGefahrenberechtigt(string $key): bool {
        $q = $this->QuelleFinden($key);
        return $q !== null && $q["Gefahrenberechtigt"];
    }

    private function AktionFinden(string $kennung): ?array {
        foreach ($this->ListeAus("Aktionen") as $a) {
            if ((string)($a["Aktionskennung"] ?? "") !== $kennung) continue;
            if (!(bool)($a["Aktiv"] ?? true)) return null;
            return ["Aktionskennung" => $kennung, "ScriptID" => (int)($a["ScriptID"] ?? 0),
                    "ErlaubteQuellen" => (string)($a["ErlaubteQuellen"] ?? ""),
                    "ErlaubteEmpfaenger" => (string)($a["ErlaubteEmpfaenger"] ?? ""),
                    "ErlaubteKanaele" => (string)($a["ErlaubteKanaele"] ?? ""),
                    "Exklusivgruppe" => (string)($a["Exklusivgruppe"] ?? ""),
                    "MaxGueltigSekunden" => (int)($a["MaxGueltigSekunden"] ?? 0),
                    "RetryBeiSicheremFehler" => (bool)($a["RetryBeiSicheremFehler"] ?? false)];
        }
        return null;
    }

    private function KonfigurationPruefen(): string {
        foreach ($this->KanalListe() as $k) {
            if ($k["Key"] === "") return "Kanal ohne Schluessel";
            // Ein Kanal darf keine Empfaengerbindung behaupten, ohne genau
            // einen Empfaenger zu bedienen - sonst waere Vertraulichkeit
            // nur ein Haekchen ohne Substanz.
            if ($k["EmpfaengerBindungNachgewiesen"]
                && ($k["Adressierung"] !== "person" || $k["EmpfaengerKey"] === "")) {
                return "Kanal " . $k["Key"] . ": Empfaengerbindung ohne exklusiven Empfaenger";
            }
            if ($k["EmpfaengerBindungNachgewiesen"] && $k["Oeffentlich"]) {
                return "Kanal " . $k["Key"] . ": oeffentlich und zugleich empfaengergebunden";
            }
        }
        return "";
    }

    // ==================================================================
    // Helfer
    // ==================================================================

    private function Liste(string $csv): array {
        $out = [];
        foreach (explode(",", $csv) as $t) { $t = trim($t); if ($t !== "") $out[] = $t; }
        return $out;
    }

    private function AlsListe($wert): array {
        if (is_array($wert)) return $wert;
        $s = trim((string)$wert);
        return $s === "" ? [] : [$s];
    }

    private function Fingerabdruck(string $quelle, string $ereignis, string $titel, string $text,
                                   string $dring, bool $vertraulich, array $zonen, array $empf,
                                   array $aktionen): string {
        // Kanonisch: sortierte Listen, feste Feldreihenfolge. Sonst haette
        // derselbe fachliche Inhalt je nach JSON-Reihenfolge verschiedene
        // Fingerabdruecke und die Deduplizierung liefe ins Leere.
        sort($zonen); sort($empf);
        $k = [];
        foreach ($aktionen as $a) $k[] = $a["aktionskennung"];
        sort($k);
        return hash("sha256", implode("\x1f", [$quelle, $ereignis, $titel, $text, $dring,
                                               $vertraulich ? "1" : "0",
                                               implode(",", $zonen), implode(",", $empf), implode(",", $k)]));
    }

    /**
     * Der Flutschutz-Schluessel entsteht SERVERSEITIG. Ein im Aufruf
     * mitgesandter Wert wuerde den Schutz wertlos machen.
     */
    private function RateKeyBilden(array $q, string $ereignis): string {
        $regel = trim($q["RateKeyRegel"]);
        if ($regel !== "") return $regel;
        return $ereignis !== "" ? $ereignis : "_text";
    }

    private function RateStempeln(string $quelle, string $rateKey, string $meldungID) {
        $lock = "MZ_Rate_" . md5($quelle . "|" . $rateKey);
        if (!IPS_SemaphoreEnter($lock, self::LOCK_MS)) return;
        try {
            $z = json_decode($this->ReadAttributeString("RateZustand"), true);
            if (!is_array($z)) $z = [];
            $z[$quelle . "|" . $rateKey] = ["letzte" => time(), "anzahl" => 0, "meldungID" => $meldungID];
            $this->WriteAttributeString("RateZustand", json_encode($z));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /** Schreibt eine NEUE Zusammenfassungszeile - aendert nie eine alte. */
    private function RateZusammenfassen() {
        $z = json_decode($this->ReadAttributeString("RateZustand"), true);
        if (!is_array($z)) return;
        $geaendert = false;
        foreach ($z as $k => $e) {
            if ((int)($e["anzahl"] ?? 0) > 0) {
                $this->JournalAnhaengen(["typ" => "flut_zusammenfassung", "schluessel" => $k,
                                         "unterdrueckteAnzahl" => (int)$e["anzahl"],
                                         "letzterZeitpunkt" => (int)($e["letzterVorfall"] ?? 0)]);
                $z[$k]["anzahl"] = 0;
                $geaendert = true;
            }
        }
        if ($geaendert) $this->WriteAttributeString("RateZustand", json_encode($z));
    }

    /**
     * Liefert true/false, oder null wenn das Signal nicht auswertbar ist.
     * Bei null entscheidet der Aufrufer: ab "wichtig" wird der Filter
     * uebersprungen (fail-open), sonst gilt er als nicht erfuellt.
     */
    private function SignalLesen(int $varID, bool $failOpen): ?bool {
        if ($varID <= 0) return null;
        if (!IPS_VariableExists($varID)) {
            $this->JournalAnhaengen(["typ" => "signal_fehlt", "variable" => $varID,
                                     "failOpen" => $failOpen]);
            return $failOpen ? null : false;
        }
        $w = GetValue($varID);
        if (is_bool($w)) return $w;
        if (is_int($w) || is_float($w)) return $w > 0;
        return null;
    }

    private function IstRuhezeit(string $von, string $bis): bool {
        if (trim($von) === "" || trim($bis) === "") return false;
        $v = $this->ZeitInSekunden($von);
        $b = $this->ZeitInSekunden($bis);
        if ($v == $b) return false;
        $jetzt = (int)date("H") * 3600 + (int)date("i") * 60;
        // Ruhezeiten laufen in der Regel ueber Mitternacht.
        if ($v < $b) return ($jetzt >= $v && $jetzt < $b);
        return ($jetzt >= $v || $jetzt < $b);
    }

    private function ZeitInSekunden(string $hhmm): int {
        $t = explode(":", trim($hhmm));
        $h = isset($t[0]) ? (int)$t[0] : 0;
        $m = isset($t[1]) ? (int)$t[1] : 0;
        if ($h < 0 || $h > 23) $h = 0;
        if ($m < 0 || $m > 59) $m = 0;
        return $h * 3600 + $m * 60;
    }

    // Standard-Hook-Registrierung nach Symcon-Muster.
    private function RegisterHook(string $hook) {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");
        if (count($ids) === 0) return;
        $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
        if (!is_array($hooks)) $hooks = [];
        foreach ($hooks as $h) {
            if ($h["Hook"] === $hook) {
                if ($h["TargetID"] == $this->InstanceID) return;
            }
        }
        $neu = [];
        foreach ($hooks as $h) if ($h["Hook"] !== $hook) $neu[] = $h;
        $neu[] = ["Hook" => $hook, "TargetID" => $this->InstanceID];
        IPS_SetProperty($ids[0], "Hooks", json_encode($neu));
        IPS_ApplyChanges($ids[0]);
    }
}
