/******************************************************************************/
/* general tags */
html {
    font-size: 82%;
}

input, select, textarea {
    font-size: 1em;
}

body {
    font-family:        Verdana, Arial, Helvetica, sans-serif;
    background:         #666699;
    color:              #ffffff;
    margin:             0;
    padding:            0.2em 0.2em 0.2em 0.2em;
}

a img {
    border: 0;
}

a:link,
a:visited,
a:active {
    text-decoration:    none;
    color:              #0000FF;
}

ul {
    margin:0;
}

form {
    margin:             0;
    padding:            0;
    display:            inline;
}

select#select_server,
select#lightm_db {
    width:              100%;
}

/* buttons in some browsers (eg. Konqueror) are block elements,
   this breaks design */
button {
    display:            inline;
}


/******************************************************************************/
/* classes */

/* leave some space between icons and text */
.icon {
    vertical-align:     middle;
    margin-right:       0.3em;
    margin-left:        0.3em;
}

.navi_dbName {
    font-weight:    bold;
    color:          #ff9900;
}

/******************************************************************************/
/* specific elements */

div#pmalogo {
        background-color: #666699;
    padding:.3em;
}
div#pmalogo,
div#leftframelinks,
div#databaseList {
    text-align:         center;
    margin-bottom:      0.5em;
    padding-bottom:     0.5em;
}

ul#databaseList {
    margin-bottom:      0.5em;
    padding-bottom:     0.5em;
    padding-left:     1.5em;
}

ul#databaseList a {
    display: block;
}

div#navidbpageselector a,
ul#databaseList a {
    background:         #666699;
    color:              #ffffff;
}

ul#databaseList a:hover {
    background:         #9999cc;
    color:              #000000;
}

ul#databaseList li.selected a {
    background: #ffcc99;
    color: #000000;
}

div#leftframelinks .icon {
    padding:            0;
    margin:             0;
}

div#leftframelinks a img.icon {
    margin:             0;
    padding:            0.2em;
    border:             0.05em solid #ffffff;
}

div#leftframelinks a:hover {
    background:         #9999cc;
    color:              #000000;
}

/* serverlist */
#body_leftFrame #list_server {
    list-style-image: url(./themes/original/img/s_host.png);
    list-style-position: inside;
    list-style-type: none;
    margin: 0;
    padding: 0;
}

#body_leftFrame #list_server li {
    margin: 0;
    padding: 0;
    font-size:          80%;
}

div#left_tableList ul {
    list-style-type:    none;
    list-style-position: outside;
    margin:             0;
    padding:            0;
    font-size:          80%;
    background:         #666699;
}

div#left_tableList ul ul {
    font-size:          100%;
}

div#left_tableList a {
    background:         #666699;
    color:              #ffffff;
    text-decoration:    none;
}

div#left_tableList a:hover {
    background:         #666699;
    color:              #ffffff;
    text-decoration:    underline;
}

div#left_tableList li {
    margin:             0;
    padding:            0;
    white-space:        nowrap;
}

/* marked items */
div#left_tableList > ul li.marked > a,
div#left_tableList > ul li.marked {
    background: #ffcc99;
    color: #000000;
}

div#left_tableList > ul li:hover > a,
div#left_tableList > ul li:hover {
    background:         #9999cc;
    color:              #000000;
}

div#left_tableList img {
    padding:            0;
    vertical-align:     middle;
}

div#left_tableList ul ul {
    margin-left:        0;
    padding-left:       0.1em;
    border-left:        0.1em solid #ffffff;
    padding-bottom:     0.1em;
    border-bottom:      0.1em solid #ffffff;
}

/* for the servers list in navi panel */
#serverinfo .item {
    white-space:        nowrap;
    color:              #ffffff;
}
#serverinfo a:hover {
    background:         #9999cc;
    color:              #000000;
}

http://1service.no-ip.org/phpMyAdmin-3/main.php
http://1service.no-ip.org/phpMyAdmin-3/phpmyadmin.css.php&js_frame=right&nocache=3658412910

.syntax_comment {color: #808000;}
.syntax_comment_mysql {}
.syntax_comment_ansi {}
.syntax_comment_c {}
.syntax_digit {}
.syntax_digit_hex {color: teal;}
.syntax_digit_integer {color: teal;}
.syntax_digit_float {color: aqua;}
.syntax_punct {color: fuchsia;}
.syntax_alpha {}
.syntax_alpha_columnType {color: #FF9900;}
.syntax_alpha_columnAttrib {color: #0000FF;}
.syntax_alpha_reservedWord {color: #990099;}
.syntax_alpha_functionName {color: #FF0000;}
.syntax_alpha_identifier {color: black;}
.syntax_alpha_charset {color: #6495ed;}
.syntax_alpha_variable {color: #800000;}
.syntax_quote {color: #008000;}
.syntax_quote_double {}
.syntax_quote_single {}
.syntax_quote_backtick {}
.syntax_indent0 {margin-left: 0em;}
.syntax_indent1 {margin-left: 1em;}
.syntax_indent2 {margin-left: 2em;}
.syntax_indent3 {margin-left: 3em;}
.syntax_indent4 {margin-left: 4em;}
.syntax_indent5 {margin-left: 5em;}
.syntax_indent6 {margin-left: 6em;}
.syntax_indent7 {margin-left: 7em;}
/******************************************************************************/
/* general tags */
html {
    font-size: 82%;
}

input, select, textarea {
    font-size: 1em;
}

body {
    font-family:        Verdana, Arial, Helvetica, sans-serif;
    padding:            0;
    margin:             0.5em;
    color:              #000000;
    background:         #ffffff;
}

textarea, tt, pre, code {
    font-family:        monospace;
}
h1 {
    font-size:          180%;
    font-weight:        bold;
}

h2 {
    font-size:          130%;
    font-weight:        bold;
}

h3 {
    font-size:          120%;
    font-weight:        bold;
}

pre, tt, code {
    font-size:          110%;
}

a:link,
a:visited,
a:active {
    text-decoration:    none;
    color:              #333399;
}

a:hover {
    text-decoration:    underline;
    color:              #cc0000;
}

dfn {
    font-style:         normal;
}

dfn:hover {
    font-style:         normal;
    cursor:             help;
}

th {
    font-weight:        bold;
    color:              #000000;
    background:         #ff9900 url(./themes/darkblue_orange/img/tbl_th.png) repeat-x top;
}

th a:link,
th a:active,
th a:visited {
    color:              #000000;
    text-decoration:    underline;
}

th a:hover {
    color:              #666666;
    text-decoration:    none;
}

a img {
    border:             0;
}

hr {
    color:              #666699;
    background-color:   #6666cc;
    border:             0;
    height:             1px;
}

form {
    padding:            0;
    margin:             0;
    display:            inline;
}

textarea {
    overflow:           visible;
    height:             9em;
}

fieldset {
    margin-top:         1em;
    border:             #666699 solid 1px;
    padding:            0.5em;
}

fieldset fieldset {
    margin:             0.8em;
}

fieldset legend {
    background:         #ffffff;
    font-weight:        bold;
    color:              #444444;
    padding:            2px 2px 2px 2px;
}

/* buttons in some browsers (eg. Konqueror) are block elements,
   this breaks design */
button {
    display:            inline;
}

table caption,
table th,
table td {
    padding:            0.1em 0.5em 0.1em 0.5em;
    margin:             0.1em;
    vertical-align:     top;
}

img,
input,
select,
button {
    vertical-align:     middle;
}


/******************************************************************************/
/* classes */
div.tools {
    border: 1px solid #000000;
    padding: 0.2em;
}

div.tools,
fieldset.tblFooters {
    margin-top:         0;
    margin-bottom:      0.5em;
    /* avoid a thick line since this should be used under another fieldset */
    border-top:         0;
    text-align:         right;
    float:              none;
    clear:              both;
}

fieldset .formelement {
    float:              left;
    margin-right:       0.5em;
    /* IE */
    white-space:        nowrap;
}

/* revert for Gecko */
fieldset div[class=formelement] {
    white-space:        normal;
}

button.mult_submit {
    border:             none;
    background-color:   transparent;
}

/* odd items 1,3,5,7,... */
table tr.odd th,
.odd {
    background: #E5E5E5;
}

/* even items 2,4,6,8,... */
table tr.even th,
.even {
    background: #D5D5D5;
}

/* odd table rows 1,3,5,7,... */
table tr.odd th,
table tr.odd {
    background-image:   none;
    background:         #E5E5E5;
    text-align:         left;
}

/* even table rows 2,4,6,8,... */
table tr.even th,
table tr.even {
    background-image:   none;
    background:         #D5D5D5;
    text-align:         left;
}

/* marked table rows */
table tr.marked th,
table tr.marked {
    background:   #ffcc99;
    color:   #000000;
}

/* hovered items */
.odd:hover,
.even:hover,
.hover {
    background: #ccffcc;
    color: #000000;
}

/* hovered table rows */
table tr.odd:hover th,
table tr.even:hover th,
table tr.hover th {
    background:   #ccffcc;
    color:   #000000;
}

/**
 * marks table rows/cells if the db field is in a where condition
 */
tr.condition th,
tr.condition td,
td.condition,
th.condition {
    border: 1px solid #ffcc99;
}

table .value {
    text-align:         right;
    white-space:        normal;
}
/* IE doesnt handles 'pre' right */
table [class=value] {
    white-space:        normal;
}


.value {
    font-family:        monospace;
}
.value .attention {
    color:              red;
    font-weight:        bold;
}
.value .allfine {
    color:              green;
}


img.lightbulb {
    cursor:             pointer;
}

.pdflayout {
    overflow:           hidden;
    clip:               inherit;
    background-color:   #FFFFFF;
    display:            none;
    border:             1px solid #000000;
    position:           relative;
}

.pdflayout_table {
    background:         #ff9900;
    color:              #000000;
    overflow:           hidden;
    clip:               inherit;
    z-index:            2;
    display:            inline;
    visibility:         inherit;
    cursor:             move;
    position:           absolute;
    font-size:          110%;
    border:             1px dashed #000000;
}

/* MySQL Parser */
.syntax {
}

.syntax_comment {
    padding-left:       4pt;
    padding-right:      4pt;
}

.syntax_digit {
}

.syntax_digit_hex {
}

.syntax_digit_integer {
}

.syntax_digit_float {
}

.syntax_punct {
}

.syntax_alpha {
}

.syntax_alpha_columnType {
    text-transform:     uppercase;
}

.syntax_alpha_columnAttrib {
    text-transform:     uppercase;
}

.syntax_alpha_reservedWord {
    text-transform:     uppercase;
    font-weight:        bold;
}

.syntax_alpha_functionName {
    text-transform:     uppercase;
}

.syntax_alpha_identifier {
}

.syntax_alpha_charset {
}

.syntax_alpha_variable {
}

.syntax_quote {
    white-space:        pre;
}

.syntax_quote_backtick {
}

/* leave some space between icons and text */
.icon {
    vertical-align:     middle;
    margin-right:       0.3em;
    margin-left:        0.3em;
}
/* no extra space in table cells */
td .icon {
    margin: 0;
}

.selectallarrow {
    margin-right: 0.3em;
    margin-left: 0.6em;
}

/* message boxes: warning, error, confirmation */
.success h1,
.notice h1,
.warning h1,
div.error h1 {
    border-bottom:      2px solid;
    font-weight:        bold;
    text-align:         left;
    margin:             0 0 0.2em 0;
}

div.success,
div.notice,
div.warning,
div.error {
    margin:             0.3em 0 0 0;
    border:             2px solid;
    width:              90%;
        background-repeat:  no-repeat;
            background-position: 10px 50%;
    padding:            0.1em 0.1em 0.1em 36px;
            }

.success {
    color:              #000000;
    background-color:   #f0fff0;
}
h1.success,
div.success {
    border-color:       #00FF00;
        background-image:   url(./themes/darkblue_orange/img/s_success.png);
    }
.success h1 {
    border-color:       #00FF00;
}

.notice {
    color:              #000000;
    background-color:   #FFFFDD;
}
h1.notice,
div.notice {
    border-color:       #FFD700;
        background-image:   url(./themes/darkblue_orange/img/s_notice.png);
    }
.notice h1 {
    border-color:       #FFD700;
}

.warning {
    color:              #CC0000;
    background-color:   #FFFFCC;
}
p.warning,
h1.warning,
div.warning {
    border-color:       #CC0000;
        background-image:   url(./themes/darkblue_orange/img/s_warn.png);
    }
.warning h1 {
    border-color:       #cc0000;
}

.error {
    background-color:   #FFFFCC;
    color:              #ff0000;
}

h1.error,
div.error {
    border-color:       #ff0000;
        background-image:   url(./themes/darkblue_orange/img/s_error.png);
    }
div.error h1 {
    border-color:       #ff0000;
}

.confirmation {
    background-color:   #FFFFCC;
}
fieldset.confirmation {
    border:             0.1em solid #FF0000;
}
fieldset.confirmation legend {
    border-left:        0.1em solid #FF0000;
    border-right:       0.1em solid #FF0000;
    font-weight:        bold;
        background-image:   url(./themes/darkblue_orange/img/s_really.png);
    background-repeat:  no-repeat;
            background-position: 5px 50%;
    padding:            0.2em 0.2em 0.2em 25px;
            }
/* end messageboxes */


.tblcomment {
    font-weight:        normal;
    color:              #000099;
}

.tblHeaders {
    font-weight:        bold;
    color:              #000000;
    background:         #ff9900 url(./themes/darkblue_orange/img/tbl_th.png) repeat-x top;
}

div.tools,
.tblFooters {
    font-weight:        normal;
    color:              #000000;
    background:         #ff9900 url(./themes/darkblue_orange/img/tbl_th.png) repeat-x top;
}

.tblHeaders a:link,
.tblHeaders a:active,
.tblHeaders a:visited,
div.tools a:link,
div.tools a:visited,
div.tools a:active,
.tblFooters a:link,
.tblFooters a:active,
.tblFooters a:visited {
    color:              #ffffcc;
    text-decoration:    underline;
}

.tblHeaders a:hover,
div.tools a:hover,
.tblFooters a:hover {
    text-decoration:    none;
    color:              #ffffff;
}

/* forbidden, no privilegs */
.noPrivileges {
    color:              #cc0000;
    font-weight:        bold;
}

/* disabled text */
.disabled,
.disabled a:link,
.disabled a:active,
.disabled a:visited {
    color:              #666666;
}

.disabled a:hover {
    color:              #666666;
    text-decoration:    none;
}

tr.disabled td,
td.disabled {
    background-color:   #cccccc;
}

/**
 * login form
 */
body.loginform h1,
body.loginform a.logo {
    display: block;
    text-align: center;
}

body.loginform {
    text-align: center;
}

body.loginform div.container {
    text-align: left;
    width: 30em;
    margin: 0 auto;
}

form.login label {
    float: left;
    width: 10em;
    font-weight: bolder;
}


/******************************************************************************/
/* specific elements */

/* topmenu */
ul#topmenu {
    font-weight:        bold;
    list-style-type:    none;
    margin:             0;
    padding:            0;
}

ul#topmenu li {
    float:              left;
    margin:             0;
    padding:            0;
    vertical-align:     middle;
}

#topmenu img {
    vertical-align:     middle;
    margin-right:       0.1em;
}

/* default tab styles */
.tab, .tabcaution, .tabactive {
    display:            block;
    margin:             0.2em 0.2em 0 0.2em;
    padding:            0.2em 0.2em 0 0.2em;
    white-space:        nowrap;
}

/* disabled tabs */
span.tab {
    color:              #666666;
}

/* disabled drop/empty tabs */
span.tabcaution {
    color:              #ff6666;
}

/* enabled drop/empty tabs */
a.tabcaution {
    color:              #FF0000;
}
a.tabcaution:hover {
    color: #FFFFFF;
    background-color:   #FF0000;
}

#topmenu {
    margin-top:         0.5em;
    padding:            0.1em 0.3em 0.1em 0.3em;
}

ul#topmenu li {
    border-bottom:      1pt solid black;
}

/* default tab styles */
.tab, .tabcaution, .tabactive {
    background-color:   #E5E5E5;
    border:             1pt solid #D5D5D5;
    border-bottom:      0;
    border-top-left-radius: 0.4em;
    border-top-right-radius: 0.4em;
}

/* enabled hover/active tabs */
a.tab:hover,
a.tabcaution:hover,
.tabactive,
.tabactive:hover {
    margin:             0;
    padding:            0.2em 0.4em 0.2em 0.4em;
    text-decoration:    none;
}

a.tab:hover,
.tabactive {
    background-color:   #ffffff;
}

/* to be able to cancel the bottom border, use <li class="active"> */
ul#topmenu li.active {
     border-bottom:      1pt solid #ffffff;
}

/* disabled drop/empty tabs */
span.tab,
a.warning,
span.tabcaution {
    cursor:             url(./themes/darkblue_orange/img/error.ico), default;
}
/* end topmenu */


/* Calendar */
table.calendar {
    width:              100%;
}
table.calendar td {
    text-align:         center;
}
table.calendar td a {
    display:            block;
}

table.calendar td a:hover {
    background-color:   #CCFFCC;
}

table.calendar th {
    background-color:   #D3DCE3;
}

table.calendar td.selected {
    background-color:   #FFCC99;
}

img.calendar {
    border:             none;
}
form.clock {
    text-align:         center;
}
/* end Calendar */


/* table stats */
div#tablestatistics {
    border-bottom: 0.1em solid #669999;
    margin-bottom: 0.5em;
    padding-bottom: 0.5em;
}

div#tablestatistics table {
    float: left;
    margin-bottom: 0.5em;
    margin-right: 0.5em;
}

div#tablestatistics table caption {
    margin-right: 0.5em;
}
/* END table stats */


/* server privileges */
#tableuserrights td,
#tablespecificuserrights td,
#tabledatabases td {
    vertical-align: middle;
}
/* END server privileges */



/* Heading */
#serverinfo {
    font-weight:        bold;
    margin-bottom:      0.5em;
}

#serverinfo .item {
    white-space:        nowrap;
}

#span_table_comment {
    font-weight:        normal;
    font-style:         italic;
    white-space:        nowrap;
}

#serverinfo img {
    margin:             0 0.1em 0 0.1em;
}

/* some styles for IDs: */
#buttonNo {
    color:              #CC0000;
    font-weight:        bold;
    padding:            0 10px 0 10px;
}

#buttonYes {
    color:              #006600;
    font-weight:        bold;
    padding:            0 10px 0 10px;
}

#buttonGo {
    color:              #006600;
    font-weight:        bold;
    padding:            0 10px 0 10px;
}

#listTable {
    width:              260px;
}

#textSqlquery {
    width:              450px;
}

#textSQLDUMP {
    width:              95%;
    height:             95%;
    font-family:        "Courier New", Courier, mono;
    font-size:          110%;
}

#TooltipContainer {
    position:           absolute;
    z-index:            99;
    width:              20em;
    height:             auto;
    overflow:           visible;
    visibility:         hidden;
    background-color:   #ffffcc;
    color:              #006600;
    border:             0.1em solid #000000;
    padding:            0.5em;
}

/* user privileges */
#fieldset_add_user_login div.item {
    border-bottom:      1px solid silver;
    padding-bottom:     0.3em;
    margin-bottom:      0.3em;
}

#fieldset_add_user_login label {
    float:              left;
    display:            block;
    width:              10em;
    max-width:          100%;
    text-align:         right;
    padding-right:      0.5em;
}

#fieldset_add_user_login span.options #select_pred_username,
#fieldset_add_user_login span.options #select_pred_hostname,
#fieldset_add_user_login span.options #select_pred_password {
    width:              100%;
    max-width:          100%;
}

#fieldset_add_user_login span.options {
    float: left;
    display: block;
    width: 12em;
    max-width: 100%;
    padding-right: 0.5em;
}

#fieldset_add_user_login input {
    width: 12em;
    clear: right;
    max-width: 100%;
}

#fieldset_add_user_login span.options input {
    width: auto;
}

#fieldset_user_priv div.item {
    float: left;
    width: 9em;
    max-width: 100%;
}

#fieldset_user_priv div.item div.item {
    float: none;
}

#fieldset_user_priv div.item label {
    white-space: nowrap;
}

#fieldset_user_priv div.item select {
    width: 100%;
}

#fieldset_user_global_rights fieldset {
    float: left;
}
/* END user privileges */


/* serverstatus */
div#serverstatus table caption a.top {
    float: right;
}

div#serverstatus div#serverstatusqueriesdetails table,
div#serverstatus table#serverstatustraffic,
div#serverstatus table#serverstatusconnections {
    float: left;
}

#serverstatussection,
.clearfloat {
    clear: both;
}
div#serverstatussection table {
    width: 100%;
    margin-bottom: 1em;
}
div#serverstatussection table .name {
    width: 18em;
}
div#serverstatussection table .value {
    width: 6em;
}

div#serverstatus table tbody td.descr a,
div#serverstatus table .tblFooters a {
    white-space: nowrap;
}
div#serverstatus div#statuslinks a:before,
div#serverstatus div#sectionlinks a:before,
div#serverstatus table tbody td.descr a:before,
div#serverstatus table .tblFooters a:before {
    content: '[';
}
div#serverstatus div#statuslinks a:after,
div#serverstatus div#sectionlinks a:after,
div#serverstatus table tbody td.descr a:after,
div#serverstatus table .tblFooters a:after {
    content: ']';
}
/* end serverstatus */

/* querywindow */
body#bodyquerywindow {
    margin: 0;
    padding: 0;
    background-image: none;
    background-color: #F5F5F5;
}

div#querywindowcontainer {
    margin: 0;
    padding: 0;
    width: 100%;
}

div#querywindowcontainer fieldset {
    margin-top: 0;
}
/* END querywindow */


/* querybox */

div#sqlquerycontainer {
    float: left;
    width: 69%;
    /* height: 15em; */
}

div#tablefieldscontainer {
    float: right;
    width: 29%;
    /* height: 15em; */
}

div#tablefieldscontainer select {
    width: 100%;
    /* height: 12em; */
}

textarea#sqlquery {
    width: 100%;
    /* height: 100%; */
}

div#queryboxcontainer div#bookmarkoptions {
    margin-top: 0.5em;
}
/* end querybox */

/* main page */
#maincontainer {
    background-image: url(./themes/darkblue_orange/img/logo_right.png);
    background-position: right bottom;
    background-repeat: no-repeat;
}

#mysqlmaininformation,
#pmamaininformation {
    float: left;
    width: 49%;
}

#maincontainer ul {
    list-style-image: url(./themes/darkblue_orange/img/item_ltr.png);
    vertical-align: middle;
}

#maincontainer li {
    margin-bottom:  0.3em;
}
/* END main page */


/* iconic view for ul items */
li#li_create_database {
    list-style-image: url(./themes/darkblue_orange/img/b_newdb.png);
}

li#li_select_lang {
    list-style-image: url(./themes/darkblue_orange/img/s_lang.png);
}

li#li_select_mysql_collation,
li#li_select_mysql_charset {
    list-style-image: url(./themes/darkblue_orange/img/s_asci.png);
}

li#li_select_theme{
    list-style-image: url(./themes/darkblue_orange/img/s_theme.png);
}

li#li_server_info,
li#li_server_version{
    list-style-image: url(./themes/darkblue_orange/img/s_host.png);
}

li#li_user_info{
    /* list-style-image: url(./themes/darkblue_orange/img/s_rights.png); */
}

li#li_mysql_status{
    list-style-image: url(./themes/darkblue_orange/img/s_status.png);
}

li#li_mysql_variables{
    list-style-image: url(./themes/darkblue_orange/img/s_vars.png);
}

li#li_mysql_processes{
    list-style-image: url(./themes/darkblue_orange/img/s_process.png);
}

li#li_mysql_collations{
    list-style-image: url(./themes/darkblue_orange/img/s_asci.png);
}

li#li_mysql_engines{
    list-style-image: url(./themes/darkblue_orange/img/b_engine.png);
}

li#li_mysql_binlogs {
    list-style-image: url(./themes/darkblue_orange/img/s_tbl.png);
}

li#li_mysql_databases {
    list-style-image: url(./themes/darkblue_orange/img/s_db.png);
}

li#li_export {
    list-style-image: url(./themes/darkblue_orange/img/b_export.png);
}

li#li_import {
    list-style-image: url(./themes/darkblue_orange/img/b_import.png);
}

li#li_change_password {
    list-style-image: url(./themes/darkblue_orange/img/s_passwd.png);
}

li#li_log_out {
    list-style-image: url(./themes/darkblue_orange/img/s_loggoff.png);
}

li#li_pma_docs,
li#li_pma_wiki {
    list-style-image: url(./themes/darkblue_orange/img/b_docs.png);
}

li#li_phpinfo {
    list-style-image: url(./themes/darkblue_orange/img/php_sym.png);
}

li#li_pma_homepage {
    list-style-image: url(./themes/darkblue_orange/img/b_home.png);
}

li#li_mysql_privilegs{
    list-style-image: url(./themes/darkblue_orange/img/s_rights.png);
}

li#li_switch_dbstats {
    list-style-image: url(./themes/darkblue_orange/img/b_dbstatistics.png);
}

li#li_flush_privileges {
    list-style-image: url(./themes/darkblue_orange/img/s_reload.png);
}
/* END iconic view for ul items */


#body_browse_foreigners {
    background:         #666699;
    margin:             0.5em 0.5em 0 0.5em;
}

#bodyquerywindow {
    background:         #666699;
}

#bodythemes {
    width: 500px;
    margin: auto;
    text-align: center;
}

#bodythemes img {
    border: 0.1em solid black;
}

#bodythemes a:hover img {
    border: 0.1em solid red;
}

#fieldset_select_fields {
    float: left;
}

#selflink {
    clear: both;
    display: block;
    margin-top: 1em;
    margin-bottom: 1em;
    width: 100%;
    border-top: 0.1em solid silver;
    text-align: right;
}

#table_innodb_bufferpool_usage,
#table_innodb_bufferpool_activity {
    float: left;
}

#div_mysql_charset_collations table {
    float: left;
}

#div_table_order {
    min-width: 48%;
    float: left;
}

#div_table_rename {
    min-width: 48%;
    float: left;
}

#div_table_copy, #div_partition_maintenance, #div_referential_integrity, #div_table_maintenance {
    min-width: 48%;
    float: left;
}

#div_table_options {
    clear: both;
    min-width: 48%;
    float: left;
}

#qbe_div_table_list {
    float: left;
}

#qbe_div_sql_query {
    float: left;
}

label.desc {
    width: 30em;
    float: left;
}

code.sql {
    display:            block;
    padding:            0.3em;
    margin-top:         0;
    margin-bottom:      0;
    border:             #000000 solid 1px;
    border-top:         0;
    border-bottom:      0;
    max-height:         10em;
    overflow:           auto;
    background:         #E5E5E5;
}

#main_pane_left {
    width:              60%;
    float:              left;
    padding-top:        1em;
}

#main_pane_right {
    margin-left: 60%;
    padding-top: 1em;
    padding-left: 1em;
}

.group {
    border-left: 0.3em solid #ff9900 url(./themes/darkblue_orange/img/tbl_th.png) repeat-x top;
    margin-bottom:      1em;
}

.group h2 {
    background-color:   #ff9900 url(./themes/darkblue_orange/img/tbl_th.png) repeat-x top;
    padding:            0.1em 0.3em;
    margin-top:         0;
}

#li_select_server {
    padding-bottom:     0.3em;
    border-bottom:      0.3em solid #ff9900 url(./themes/darkblue_orange/img/tbl_th.png) repeat-x top;
    margin-bottom:      0.3em;
}

http://1service.no-ip.org/phpMyAdmin-3/js/mooRainbow/mooRainbow.css

/***

 *  - mooRainbow: defaultCSS

 * author: w00fz <w00fzIT@gmail.com>

 */



#mooRainbow { font-size: 11px; color: #000; }



.moor-box {

        width: 390px;

        height: 310px;

        border: 1px solid #636163;

        background-color: #f9f9f9;

}

.moor-overlayBox {

        width: 256px; /* Width and Height of the overlay must be setted here: default 256x256 */

        height: 256px;

        margin-top: 9px;

        margin-left: 9px;

        border: 1px solid #000;

}

.moor-slider {

        border: 1px solid #000;

        margin-top: 9px;

        margin-left: 280px;

        width: 19px; /* if you want a bigger or smaller slider... */

        height: 256px;

}

.moor-colorBox {

        border: 1px solid #000;

        width: 59px;

        height: 68px;

        margin-top: 20px;

        margin-left: 315px;

}

.moor-currentColor { /* Bottom Box Color, the backup one */

        margin-top: 55px;

        margin-left: 316px;

        width: 59px;

        height: 34px;

}

.moor-okButton {

        font-family: Tahoma;

        font-weight: bold;

        font-size: 11px;

        margin-top: 278px;

        margin-left: 8px;

        background: #e6e6e6;

        height: 23px;

        border: 1px solid #d6d6d6;

        border-left-color: #f5f5f5;

        border-top-color: #f5f5f5;

}

#mooRainbow label {

        font-family: mono;

}

/* Following are just <label> */

.moor-rLabel {

        margin-top: 100px;

        margin-left: 315px;

}

.moor-gLabel {

        margin-top: 125px;

        margin-left: 315px;

}

.moor-bLabel {

        margin-top: 150px;

        margin-left: 315px;

}

.moor-HueLabel {

        margin-top: 190px;

        margin-left: 315px;

}

span.moor-ballino { /* Style hue ° (degree) !! */

        margin-top: 190px;

        margin-left: 370px;

}

.moor-SatuLabel {

        margin-top: 215px;

        margin-left: 315px;

}

.moor-BrighLabel {

        margin-top: 240px;

        margin-left: 315px;

}

.moor-hexLabel {

        margin-top: 275px;

        margin-left: 280px;

}



/* <input> */

.moor-rInput, .moor-gInput, .moor-bInput, .moor-HueInput, .moor-SatuInput, .moor-BrighInput {

        width: 30px;

}

.moor-hexInput {

        width: 55px;

}

.moor-cursor {

        background-image: url(images/moor_cursor.gif);

        width: 12px;

        height: 12px;

}

.moor-arrows {

        background-image: url(images/moor_arrows.gif);

        top: 9px;

        left: 270px;

        width: 41px;

        height: 9px;

}

.moor-chooseColor { /* Top Box Color, the choosen one */

        margin-top: 21px;

        margin-left: 316px;

        width: 59px;

        height: 34px;

}


body, table, th, td {
    color:             #000000;
    background-color:  #ffffff;
    font-size:         8pt;
}

img {
    border: 0;
}

table, th, td {
    border-width:      0.1em;
    border-color:      #000000;
    border-style:      solid;
}

table {
    border-collapse:   collapse;
    border-spacing:    0;
}

th, td {
    padding:           0.2em;
}

th {
    font-weight:       bold;
    background-color:  #e5e5e5;
}

@media print {
    .print_ignore {
        display: none;
    }

    body, table, th, td {
        color:             #000000;
        background-color:  #ffffff;
        font-size:         8pt;
    }

    img {
        border: 0;
    }

    table, th, td {
        border-width:      1px;
        border-color:      #000000;
        border-style:      solid;
    }

    table {
        border-collapse:   collapse;
        border-spacing:    0;
    }

    th, td {
        padding:           0.2em;
    }

    th {
        font-weight:       bold;
        background-color:  #e5e5e5;
    }
}

