Du bist ein Autor von wissenschaftlichen Arbeiten.

## Regeln für den Inhalt:

Verfasse eine wissenschaftliche Arbeit auf Deutsch über das Thema

Implementierung einer Datenbank zur Speicherung der Arbeitszeitdaten sowie Entwicklung eines passenden Gehäuses.

Das Untersuchungsanliegen ist wie folgt:

Planung, Test und Erstellung einer Datenbank zur Speicherung der erfassten Daten. Zusätzlich Konstruktion, Druck und Prüfung eines selbst entworfenen Gehäuses für die Hardware.


## Inhaltsverzeichnis

- Grundlagen des 3D-Druckes
  - Funktionsprinzip der additiven Fertigung
  - Das FDM/FFF-Verfahren
  - Aufbau und Funktionsweise eines FDM-Druckers
  - Vom 3D-Modell zum Druck: der Slicing-Prozess
  - Schichtaufbau, Haftung und typische Druckfehler

- Auswahl der Druckparameter und Kunststoffe
  - Anforderungen an das Gehäuse
  - Vergleich gängiger Kunststoffe (PLA, PETG, ASA)
  - Begründung der Materialwahl
  - Schichthöhe und Auflösung
  - Fülldichte und Füllmuster (Infill)
  - Wand- und Bodenstärke
  - Temperaturführung (Düse, Druckbett, Kammer)
  - Stützstrukturen und Überhänge
  - Druckhaftung und Plattenauswahl

- Konstruktion und Prüfung des Gehäuses
  - Konstruktionsanforderungen der Hardware
  - Erste Konstruktion (Design 1)
  - Konstruktive Schwachstellen des Schnappverschlusses
  - Überarbeitete Konstruktion (Design 2)
  - Vergleich und Bewertung beider Gehäuse
  - Druckergebnis und Funktionsprüfung

- Datenbankschema der Applikation
  - Planung des Datenmodells an Hand von use cases
  - Realisiertes physisches Schema
  - Test und Validierung des Schemas

- PHP als serverseitige Skriptsprache
  - Client-Server-Modell und Request-Response-Zyklus
  - Verarbeitung von Formulardaten (GET und POST)
  - Sitzungsverwaltung mit Sessions
  - Sicherheitsaspekte (Authentifizierung, Passwort-Hashing)

- Datenbankzugriff mit PHP
  - Die MySQLi-Schnittstelle
  - Prepared Statements und Parameterbindung
  - Schutz vor SQL-Injection
  - Fehlerbehandlung beim Datenbankzugriff

- Integration von JavaScript in PHP
  - Zusammenspiel von Server- und Clientseite
  - Ausgabe dynamischer JavaScript-Inhalte aus PHP
  - Asynchrone Datenübertragung mit AJAX
  - Datenaustausch im JSON-Format
  - Beispiel: dynamisches Nachladen von Daten

- Literaturverzeichnis
- Abbildungsverzeichnis


## Regeln für das Verfassen der Arbeit

Schreibe sehr ausführlich und gehe auch auf andere mögliche Lösungsvarianten ein.
Beschreibe vorher das Grundkonzept der jeweiligen Technologie und leite dann in den konkreten Anwendungsfall über.
Setze die Lösungen im Source Code in einen größeren Gesamtkontext.
Generiere wenn es passend ist PlantUML Diagramme und bette sie in die Arbeit ein.

## Quellen

- Verwende die Source Codes in `DiplomProjekt - Alzo` und Druckdaten aus `3D_Druck - Alzo`.
- Verwende im Internet befindliche Quellen zum 3D Drucker `Bambu Lab P2S` auf der Herstellerseite.
- Füge, wenn dies passend ist, Codesnippets aus der Quelle ein.
- Fasse den Code auf die wesentlichensten Punkte zusammen und erstelle wenn nötig Codekommentare auf Englisch.
- Callouts sollen auf Deutsch geschrieben werden.
- WICHTIG: Verwende nicht nur den angefügten Source Code als Quelle. Suche für Hintergrundinformationen vertrauenswürdige Quellen aus dem Web.


## Regeln für die Ausgabe

- Gib die Arbeit als AsciiDoc in der Datei `diplomarbeit_alzo.adoc` aus.
- Schreibe nur 1 Satz pro Zeile.
- Füge vor Listen immer eine Leerzeile ein.
- Der Titel hat den Level 2 (==), da das Dokument mit include in ein übergeordnetes Dokument eingebunden wird.
- Verwende keine Markdown Zitierungen wie cite oder andere, nur in Markdown gültige Syntax.
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