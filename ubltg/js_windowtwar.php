<html><head><title>Test</title>
<script type="text/javascript">
function FensterOeffnen () {
  Fenster = window.open("datei.htm", "Zweitfenster1", "width=300,height=200");
  if (Fenster.locationbar) {
    if (Fenster.locationbar.visible == false) {
      Fenster.close();
      Neufenster = window.open("datei.htm", "Zweitfenster2", "width=300,height=200,location=yes");
      Neufenster.focus();
    }
  } else {
    alert("Ihr Browser gibt nicht Preis, ob das neue Fenster eine Adressleiste hat.");
  }
}
</script>
</head><body>
<p><a href="javascript:FensterOeffnen()">Fenster &ouml;ffnen</a></p>
</body></html>