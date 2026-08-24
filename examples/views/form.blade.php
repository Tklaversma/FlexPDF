<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Account application</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Plex Sans'; font-size: 9pt; line-height: 1.5; color: #16241d; }

        .head { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2pt solid #2f6f4e; padding-bottom: 10pt; margin-bottom: 18pt; }
        .head h1 { font-size: 18pt; margin: 0; letter-spacing: -0.4pt; }
        .head .kind { font-size: 7pt; font-weight: bold; letter-spacing: 2.2pt; text-transform: uppercase; color: #2f6f4e; }
        .head .ref { font-family: 'Plex Mono'; font-size: 8pt; color: #7c8a83; text-align: right; }

        .intro { font-size: 8.5pt; color: #56655e; margin-bottom: 16pt; }

        h2 { font-size: 8pt; letter-spacing: 1.8pt; text-transform: uppercase; color: #2f6f4e;
             border-bottom: 0.5pt solid #dbe6e0; padding-bottom: 4pt; margin: 13pt 0 8pt; }

        /* Every field below with a name becomes a real form field in the PDF. */
        .row { display: flex; gap: 12pt; margin-bottom: 7pt; }
        .field { flex: 1; }
        .field.narrow { flex: 0 0 120pt; }
        label { display: block; font-size: 6.8pt; letter-spacing: 1pt; text-transform: uppercase; color: #7c8a83; margin-bottom: 3pt; }
        input[type=text], input[type=email], input[type=password], select, textarea {
            width: 100%; font-family: 'Plex Sans'; font-size: 9pt; color: #16241d;
            border: 0.75pt solid #cddbd4; background: #fafcfb; padding: 5pt 6pt;
        }
        select { height: 24pt; }
        textarea { height: 44pt; }

        .choices { display: flex; gap: 18pt; font-size: 8.5pt; }
        .choices div { display: flex; align-items: center; gap: 5pt; }
        input[type=checkbox], input[type=radio] { width: 10pt; height: 10pt; }

        .consent { display: flex; gap: 8pt; align-items: flex-start; background: #f2f7f4; border-left: 3pt solid #2f6f4e; padding: 9pt 12pt; margin-top: 12pt; font-size: 8pt; color: #56655e; }
        .consent input { margin-top: 2pt; }

        .sign { display: flex; gap: 20pt; margin-top: 12pt; }
        .sign .field { flex: 1; }
        .sign .box { height: 36pt; border: 0.75pt dashed #cddbd4; }

        .fine { font-size: 6.8pt; color: #9aa8a1; margin-top: 12pt; }
    </style>
</head>
<body>

<div class="head">
    <div>
        <div class="kind">{{ $company['name'] }}</div>
        <h1>Account application</h1>
    </div>
    <div class="ref">Form NB-01<br>Fill in on screen, then save or print</div>
</div>

<p class="intro">
    Fill this form in directly in your PDF reader. Text fields, check boxes, radio buttons and drop-downs
    are real form fields, so what you type is saved with the file.
</p>

<h2>Your business</h2>
<div class="row">
    <div class="field">
        <label for="business">Business name</label>
        <input type="text" id="business" name="business" placeholder="Registered or trading name">
    </div>
    <div class="field narrow">
        <label for="company_number">Company number</label>
        <input type="text" id="company_number" name="company_number">
    </div>
</div>
<div class="row">
    <div class="field">
        <label for="address">Address</label>
        <input type="text" id="address" name="address">
    </div>
    <div class="field narrow">
        <label for="postcode">Postcode</label>
        <input type="text" id="postcode" name="postcode">
    </div>
</div>
<div class="row">
    <div class="field">
        <label for="type">Type of business</label>
        <select id="type" name="type">
            <option>Sole trader</option>
            <option selected>Limited company</option>
            <option>Partnership</option>
            <option>Charity</option>
        </select>
    </div>
    <div class="field">
        <label for="year_end">Financial year end</label>
        <select id="year_end" name="year_end">
            <option>31 March</option>
            <option>30 June</option>
            <option>30 September</option>
            <option selected>31 December</option>
        </select>
    </div>
</div>

<h2>Contact</h2>
<div class="row">
    <div class="field">
        <label for="contact">Contact name</label>
        <input type="text" id="contact" name="contact">
    </div>
    <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email">
    </div>
    <div class="field narrow">
        <label for="phone">Phone</label>
        <input type="text" id="phone" name="phone">
    </div>
</div>

<h2>Plan</h2>
<div class="row">
    <div class="field">
        <label>Which plan</label>
        <div class="choices">
            <div><input type="radio" name="plan" value="start" id="plan_start"><label for="plan_start" style="margin: 0; text-transform: none; letter-spacing: 0; font-size: 8.5pt; color: #16241d">Start, &pound; 12</label></div>
            <div><input type="radio" name="plan" value="pro" id="plan_pro" checked><label for="plan_pro" style="margin: 0; text-transform: none; letter-spacing: 0; font-size: 8.5pt; color: #16241d">Pro, &pound; 29</label></div>
            <div><input type="radio" name="plan" value="practice" id="plan_practice"><label for="plan_practice" style="margin: 0; text-transform: none; letter-spacing: 0; font-size: 8.5pt; color: #16241d">Practice, &pound; 89</label></div>
        </div>
    </div>
</div>
<div class="row">
    <div class="field">
        <label>Add-ons</label>
        <div class="choices">
            <div><input type="checkbox" name="addon_bank" id="addon_bank" checked><label for="addon_bank" style="margin: 0; text-transform: none; letter-spacing: 0; font-size: 8.5pt; color: #16241d">Bank Feed Plus</label></div>
            <div><input type="checkbox" name="addon_time" id="addon_time"><label for="addon_time" style="margin: 0; text-transform: none; letter-spacing: 0; font-size: 8.5pt; color: #16241d">Time module</label></div>
            <div><input type="checkbox" name="addon_scan" id="addon_scan"><label for="addon_scan" style="margin: 0; text-transform: none; letter-spacing: 0; font-size: 8.5pt; color: #16241d">Scan and Capture</label></div>
        </div>
    </div>
</div>
<div class="row">
    <div class="field">
        <label for="notes">Anything we should know</label>
        <textarea id="notes" name="notes" placeholder="Current software, migration wishes, questions"></textarea>
    </div>
</div>

<h2>Sign-in</h2>
<div class="row">
    <div class="field">
        <label for="username">Choose a user name</label>
        <input type="text" id="username" name="username">
    </div>
    <div class="field">
        <label for="password">Choose a password</label>
        <input type="password" id="password" name="password">
    </div>
</div>

<div class="consent">
    <input type="checkbox" name="consent" id="consent">
    <label for="consent" style="margin: 0; text-transform: none; letter-spacing: 0; font-size: 8pt; color: #56655e">
        I agree to the terms of service and confirm that the details above are correct. Northbound may
        contact me about this application.
    </label>
</div>

<div class="sign">
    <div class="field">
        <label>Signature</label>
        <div class="box"></div>
    </div>
    <div class="field">
        <label for="signed_on">Date</label>
        <input type="text" id="signed_on" name="signed_on">
    </div>
</div>

<p class="fine">
    Return the completed form to {{ $company['email'] }}. The password field shows dots and its value is
    never written into the file, so this form is safe to send on.
</p>

</body>
</html>
