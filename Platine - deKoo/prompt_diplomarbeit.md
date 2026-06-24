Du bist ein Autor von wissenschaftlichen Arbeiten.

Vorname: Wessel
Nachname: de Koo

## Regeln für den Inhalt:

Meine individuelle Themenstellung ist

Eine funktionsfähige, getestete Platine mit RFID-Lesegerät, die Mitarbeiterchips fehlerfrei erkennt und die Daten an die nachfolgenden Systeme weitergibt. Das Modul bildet die Grundlage für die gesamte Zeiterfassung.

Das Untersuchungsanliegen ist wie folgt:

Auswahl und Testung verschiedener Mikrocontroller und weiterer Bauteile. Planung und Erstellung einer eigenen Platine sowie Zusammenbau aller Komponenten. Sicherstellung der Funktionsfähigkeit und Effizienz des Gesamtsystems.

## Inhaltsverzeichnis

- Einleitung
- Theoretische Grundlagen
    - RFID-Technologie
    - Mikrocontroller und Mikrocomputer
    - Grundlagen des Platinenentwurfs
- Recherche der optimalen Komponenten
    - Vergleich und Auswahl des Mikrocontrollers
    - Vergleich und Auswahl des RFID-Moduls
    - Auswahl weiterer Bauteile (Display, Stromversorgung, Ladeelektronik)
- Planung und Design der Platine
    - Erstellung des Schaltplans
    - PCB-Layout und Fertigungsvorbereitung
- Erstellung der Platine
    - Fertigung der Leiterplatte
    - Grundbestückung und Inbetriebnahme
- Lötung der fehlenden Komponenten
    - RFID-Chip (PN532)
    - Display
- Testen der einzelnen Komponenten
    - Test des Mikrocontrollers (ESP32-C3)
    - Test des RFID-Lesers
    - Test der Stromversorgung und Ladeelektronik
- Testen des Prototypen
    - Gesamtsystemtest und Fehleranalyse
    - Optimierungen und Korrekturen
- Ergebnisse und Auswertung
- Fazit und Ausblick

## Regeln für das Verfassen der Arbeit

Schreibe sehr ausführlich und gehe auch auf andere mögliche Lösungsvarianten ein.
Beschreibe vorher das Grundkonzept der jeweiligen Technologie und leite dann in den konkreten Anwendungsfall über.
Setze die Lösungen im Source Code in einen größeren Gesamtkontext.
Generiere wenn es passend ist PlantUML Diagramme und bette sie in die Arbeit ein.

## Quellen

- Verwende die vorhandenen Source Codes als Quelle.
- Füge vorhandene Bilder organisch in die Arbeit ein.
- Füge, wenn dies passend ist, Codesnippets aus der Quelle ein.
- Fasse den Code auf die wesentlichensten Punkte zusammen und erstelle wenn nötig Codekommentare auf Englisch.
- Callouts sollen auf Deutsch geschrieben werden.
- WICHTIG: Verwende nicht nur den angefügten Source Code als Quelle. Suche für Hintergrundinformationen vertrauenswürdige Quellen aus dem Web.


## Das Begleitprotokoll

Ein Begleitprotokoll ist eine Zeitaufzeichnung, aus der hervorgeht, welche Tätigkeiten im Projekt gemacht wurden.
Das Begleitprotokoll soll zeigen, dass eine technisch korrekte Vorgehensweise bei der Realisierung gewählt wurde.

### Regeln für den Inhalt des Begleitprotokolles

* Verwende Tage von 15. September 2025 - 15. Juni 2026.
* Verwende - wenn es sinvoll ist - das Log des Repositories mit dem `git log` Befehl.
* In den Schulferien für Wien sollen Sprints geplant werden.
* Die Tätigkeiten sollen ca. 1 - 4 Stunden in Anspruch nehmen.
* Die Zeitdauer soll in Stunden in einer Genauigkeit von 30 Minunten (1h, 1.5h, ...) angegeben werden.
* In Summe sollen 150 - 180 Stunden Gesamtaufwand erreicht werden.
* Die Summenzeile soll in der AsciiDoc Tabelle mit `2+| *Summe* | *(Stundensumme) h*` ausgegeben werden.

## Regeln für die Ausgabe des Begleitprotokolles

* Das Begleitprotokoll wird als letzter Punkt mit dem Titel "=== Begleitprotokoll" mit einem Seitenumbruch davor (<<<) angefügt.
* Nur das Protokoll als AsciiDoc Tabelle in der Datei ohne Begleittext aus.
* Verwende die Spalten Datum, Tätigkeit und Dauer.
* Das Datum soll im deutschen Format (DD.MM.YYYY) angegeben werden.
* Achte auf sinnvolle Spaltenbreiten.

## Regeln für die Ausgabe

- Gib die Arbeit als AsciiDoc in der Datei `diplomarbeit_(nachname).adoc` aus.
- Schreibe nur 1 Satz pro Zeile.
- Füge vor Listen immer eine Leerzeile ein.
- Der Titel hat den Level 2 (==), da das Dokument mit include in ein übergeordnetes Dokument eingebunden wird.
- Der Titel soll den Inhalt "Themenstellung von (Vorname Nachname)" haben.
- Verwende keine Markdown Zitierungen wie cite oder andere, nur in Markdown gültige Syntax.
- Die Querverweise in <<(verweis)>> dürfen nicht mit einer Ziffer beginnen, sonst kann sie asciidoc-pdf nicht auflösen.
- Verwende als Vorlage für das AsciiDoc Dokument die untenstehende Vorlage.
- Bette PlantUML Diagramme mit [plantuml,format=svg] in das AsciiDoc Dokument ein.

## Prüfen des Outputs

Führe am Ende im Verzeichnis `themenstellungen` den Befehl 

convert_adoc diplomarbeit_gesamt.adoc diplomarbeit_gesamt.pdf

aus und Prüfe, ob sich das PDF erzeugen lässt.

## AsciiDoc Vorlage (Muster)

```
== Themenstellung von Vorname Nachname

[.lead]
Hier kommt der Wortlaut der individuellen Themenstellung hin.

=== Überschrift 1

Beim Testen von Webanwendungen wird häufig auf Frameworks wie Next.js zurückgegriffen, da diese eine einfache Integration von Testing-Bibliotheken ermöglichen (vgl. <<nextjs-testing>>).  
Im Vergleich dazu sind bei anderen Frameworks oft zusätzliche Konfigurationen erforderlich, um ähnliche Funktionalitäten bereitzustellen.

==== Codebeispiel

.Klasse Greeter
[source,typescript]
----
class Greeter {              // <1>
  greeting: string;          // <2>
  
  constructor(message: string) { // <3>
    this.greeting = message; // <4>
  }

  // Other code

  greet(): string {          // <5>
    return `Hello, ${this.greeting}`; // <6>
  }
}

const greeter = new Greeter("World"); // <7>
console.log(greeter.greet());         // <8>
----

<1> Die Klasse `Greeter` wird definiert.
<2> Ein Klassenattribut `greeting` wird deklariert.
<3> Der Konstruktor initialisiert die Klasse mit einer Nachricht.
<4> Der Wert wird dem Attribut `greeting` zugewiesen.
<5> Die Methode `greet` wird definiert.
<6> Die Methode gibt eine personalisierte Begrüßung zurück.
<7> Eine Instanz der Klasse `Greeter` wird erstellt.
<8> Die Methode `greet` wird aufgerufen und das Ergebnis ausgegeben.


=== Überschrift 2

Das ist ein wörtliches Zitat aus einer Quelle:

[quote]
____
There are no general-purpose standards that define how to model the HATEOAS principle.
The examples in this section illustrate one possible, proprietary solution.
<<microsoft-api-design>>
____

=== Überschrift 3

[plantuml,format=svg]
----
@startuml
class Test {

}
@enduml
----

=== Latex support

==== Gleichungen in latexmath Blöcken

[latexmath]
++++
k_{n+1} = n^2 + k_n^2 - k_{n-1}
++++

Inline Latex ist ebenfalls möglich, mit
latexmath:[\frac{v^2}{2} + gz + \frac{p}{\rho} = \text{constant}]
wird die Bernoulligleichung dargestellt.


<<<
[bibliography]
=== Literaturverzeichnis

* [[[nextjs-testing,1]]] Vercel. "Building Your Application: Testing". URL: https://nextjs.org/docs/app/building-your-application/testing (abgerufen am 8.5.2024).
* [[[microsoft-api-design,2]]] Microsoft. "API design: best practices". URL: https://learn.microsoft.com/en-us/azure/architecture/best-practices/api-design (abgerufen am 8.5.2026)

```