# Browserfähige Hinweistöne

Die drei WAV-Dateien werden vollständig und reproduzierbar im Projekt erzeugt.
Der Generator synthetisiert mit reiner Ganzzahlarithmetik unterscheidbare
Dreieckswellen und schreibt browserfähige Mono-WAV-Dateien mit PCM-16 und
22.050 Hz. Es werden keine externen Tonaufnahmen oder Audiobibliotheken
verwendet.

- `notify_aw.wav`: Fernmelder, Warteschlange `old_que_aw`
- `notify_si.wav`: Sichter, Warteschlange `old_que_si`
- `notify_stab.wav`: Stab und Fachberater, Warteschlange `old_que_stab`

Die Anwendung bindet ausschließlich diese internen URLs ein. Ein Browser
spielt sie erst nach der ausdrücklichen Freigabe über „Hinweistöne
aktivieren“. Die automatischen Tests prüfen RIFF/WAVE-Struktur, PCM-Format,
Auslösezustände und den angeforderten Wiedergabeaufruf. Ob ein Zielgerät den
Ton physisch hörbar ausgibt, bleibt Teil der manuellen Abnahme.

Töne neu erzeugen:

```console
php tools/generate_notification_tones.php --write
```

Prüfen, dass die eingecheckten Dateien exakt aus dem Generator stammen:

```console
php tools/generate_notification_tones.php --verify
```
