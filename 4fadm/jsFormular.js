var color_err = '#FF4500';
var color_warning = '#FFD700';
var color_ok = '#32CD32';
var color_empty = '#ffffff';

function isValid(x) {
        var RegExp = /^(\w*)$/;
        var result = x.match(RegExp);
        return !(result);
}

function checkAll() {
        for ( var i = 0; i < Felder.length; i++) {
                checkInput(Felder[i]);
        }
}

function checkForm() {
  var check = 0;
  var ausgabe = "";
  var checked = new Array();

  for ( var i = 0; i < Felder.length; i++) {
    if ((check = checkInput(Felder[i])) != 0) {
      for (var j = 0; j < checked.length; j++) {
        if(checked[j] == document.getElementById(Felder[i]).value) {
          check = 0;
        }
      }
      if(check==1) {
        ausgabe = ausgabe + "Fehler: " + document.getElementById(Felder[i]).value + " ist mehrfach vergeben.\n";
      } else if(check==2) {
        ausgabe = ausgabe + "Fehler: " + document.getElementById(Felder[i]).value + " enthält ungültige Zeichen.\n";
      }
      checked.push(document.getElementById(Felder[i]).value);
    }
  }
  if (ausgabe != "" ) {
    ausgabe = 'Bitte Eingaben überprüfen!\n' + ausgabe;
    alert(ausgabe);
    return false;
  }
  return true;
}

function checkInput(ident) {
  var check = 0;
  if (document.getElementById) {
    document.getElementById(ident).value = document.getElementById(ident).value.toUpperCase();

    for ( var i = 0; i < Felder.length; i++) {
      if (Felder[i] != ident) {
        if (document.getElementById(ident).value == document.getElementById(Felder[i]).value) {
          check = 1;
        }
      }
    }

    if ((check == 1) || (isValid(document.getElementById(ident).value))) {
      if (document.getElementById(ident).value == '') {
        document.getElementById(ident).style.backgroundColor = color_empty;
        check = 0;
      } else {
        document.getElementById(ident).style.backgroundColor = color_err;
        if(check == 0) check = 2;
      }
    } else {
      document.getElementById(ident).style.backgroundColor = color_ok;
    }
  }
  return check;
}
