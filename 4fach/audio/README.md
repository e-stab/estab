# Browserfähige Hinweistöne

Die drei WAV-Dateien sind PCM-16-Kopien der bestehenden Töne aus
`4fach/design/HS/`. Die Originaldateien bleiben unverändert. Die Kopien
vermeiden das von Browsern nicht zuverlässig unterstützte Microsoft-ADPCM der
Fernmelder- und Sichtertöne.

- `notify_aw.wav`: Fernmelder, Warteschlange `old_que_aw`
- `notify_si.wav`: Sichter, Warteschlange `old_que_si`
- `notify_stab.wav`: Stab und Fachberater, Warteschlange `old_que_stab`

Die Anwendung bindet ausschließlich diese internen URLs ein. Ein Browser
spielt sie erst nach der ausdrücklichen Freigabe über „Hinweistöne
aktivieren“. Die automatischen Tests prüfen RIFF/WAVE-Struktur, PCM-Format,
Auslösezustände und den angeforderten Wiedergabeaufruf. Ob ein Zielgerät den
Ton physisch hörbar ausgibt, bleibt Teil der manuellen Abnahme.

Reproduzierbare Neukodierung mit FFmpeg:

```console
ffmpeg -hide_banner -loglevel error -y -fflags +bitexact \
  -i 4fach/design/HS/notify_aw.wav -map_metadata -1 \
  -c:a pcm_s16le -flags:a +bitexact 4fach/audio/notify_aw.wav
```

Für `notify_si.wav` und `notify_stab.wav` werden Ein- und Ausgabename
entsprechend ersetzt. Abtastrate und Kanalzahl übernimmt FFmpeg dabei aus der
jeweiligen Quelle.
