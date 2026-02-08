<?php
/**
 * Owner PDF Template
 *
 * Variables available:
 * @var array $d
 */

$e = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$logo = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.webp';

/**
 * Build full guest address (safe fallback)
 */
$guest_address_lines = [];
if (!empty($d['guest_addr'])) {
    $guest_address_lines[] = $d['guest_addr'];
}
if (!empty($d['guest_zip']) || !empty($d['guest_city'])) {
    $guest_address_lines[] = trim(
        ($d['guest_zip'] ?? '') . ' ' . ($d['guest_city'] ?? '')
    );
}
if (!empty($d['guest_country'])) {
    $guest_address_lines[] = $d['guest_country'];
} else {
    // ✅ fallback country
    $guest_address_lines[] = 'Deutschland';
}
$guest_address = implode('<br>', array_map($e, $guest_address_lines));

// ✅ fallback owner name
$owner_name = !empty($d['owner_name']) ? $d['owner_name'] : '—';
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">

<style>
body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    color: #212F54;
    line-height: 1.45;
}

table {
    border-collapse: collapse;
    width: 100%;
}

.header td {
    vertical-align: top;
}

.logo img {
    height: 36px;
}

.contact {
    text-align: right;
    font-size: 10.5px;
    color: #555;
}

h1 {
    font-size: 18px;
    margin: 12px 0 6px 0;
}

.subline {
    font-size: 10.5px;
    color: #555;
    margin-bottom: 14px;
}

.box {
    border: 1px solid #D3D7E0;
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 10px;
}

.label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #7A8193;
    margin-bottom: 4px;
}

.value {
    font-size: 12.5px;
    font-weight: 700;
}

.note {
    font-size: 10.5px;
    color: #555;
}
</style>
</head>

<body>

<table class="header">
<tr>
    <td class="logo">
        <img src="<?php echo $e($logo); ?>" alt="Stay4Fair">
    </td>
    <td class="contact">
        <strong>Stay4Fair.com</strong><br>
        Tel / WhatsApp: +49 176 24615269<br>
        E-Mail: business@stay4fair.com<br>
        Owner Portal: stay4fair.com/owner-bookings/
    </td>
</tr>
</table>

<h1>Buchungsbestätigung (Besitzer) – #<?php echo $e($d['booking_id']); ?></h1>

<div class="subline">
    Business Model: <strong><?php echo $e($d['business_model']); ?></strong>
    · Dokumenttyp: <?php echo $e($d['document_type']); ?>
</div>

<div class="box">
    <div class="label">Apartment</div>
    <div class="value">
        <?php echo $e($d['apt_title']); ?> (ID <?php echo $e($d['apt_id']); ?>)
    </div>
    <div class="note" style="margin-top:6px">
        Adresse: <?php echo $e($d['apt_address']); ?><br>
        Vermieter: <strong><?php echo $e($owner_name); ?></strong>
    </div>
</div>

<div class="box">
    <div class="label">Zeitraum</div>
    <div class="value">
        <?php echo $e($d['check_in']); ?> – <?php echo $e($d['check_out']); ?>
    </div>
    <div class="note" style="margin-top:6px">
        Nächte: <?php echo $e($d['nights']); ?> · Gäste: <?php echo $e($d['guests']); ?>
    </div>
</div>

<div class="box">
    <div class="label">Gast / Rechnungskontakt</div>
    <div class="note">
        <?php echo $e($d['guest_name']); ?><br>
        <?php if (!empty($d['guest_company'])) : ?>
            Firma: <?php echo $e($d['guest_company']); ?><br>
        <?php endif; ?>
        <?php echo $e($d['guest_email']); ?> · <?php echo $e($d['guest_phone']); ?><br>
        Adresse:<br>
        <?php echo $guest_address ?: '—'; ?>
    </div>
</div>

<div class="box">
    <div class="label">Auszahlung an Vermieter</div>
    <div class="value"><?php echo $e($d['payout']); ?> €</div>
</div>

<?php if (!empty($d['pricing'])) : ?>
<div class="box">
    <div class="label">Provision & Vermittlungsgebühr</div>
    <div class="note">
        Provision: <?php echo $e($d['pricing']['commission_rate'] * 100); ?> %<br>
        Provision (netto): <?php echo $e(number_format($d['pricing']['commission_net_total'], 2, ',', '.')); ?> €<br>
        MwSt auf Provision (19%): <?php echo $e(number_format($d['pricing']['commission_vat_total'], 2, ',', '.')); ?> €<br>
        <strong>Provision (brutto): <?php echo $e(number_format($d['pricing']['commission_gross_total'], 2, ',', '.')); ?> €</strong>
    </div>
</div>
<?php elseif (strpos($d['business_model'], 'Modell B') !== false) : ?>
<div class="box">
    <div class="label">Provision & Vermittlungsgebühr</div>
    <div class="note">
        Die Provisionsdaten konnten für diese Buchung nicht ermittelt werden.<br>
        Bitte prüfen Sie die Abrechnung im Owner Portal.
    </div>
</div>
<?php endif; ?>

<div class="box">
    <div class="label">Hinweis zu Stornierungen</div>
    <div class="note">
        Es gelten die vom Vermieter akzeptierten Bedingungen.<br>
        Details: https://stay4fair.com/owner-terms-agb/
    </div>
</div>

<div class="box">
    <div class="label">Auszahlungs- und Steuerhinweis</div>
    <div class="note">
        Die Auszahlung erfolgt in der Regel innerhalb von 3–7 Werktagen nach Abreise
        des Gastes auf das angegebene Konto des Vermieters oder nach individueller
        Vereinbarung in bar (bis max. 20 Werktage).<br><br>
        Die erzielten Einkünfte aus der kurzfristigen Vermietung sind steuerpflichtig.
        Die Verantwortung für die ordnungsgemäße Versteuerung liegt ausschließlich
        beim Vermieter.
    </div>
</div>

</body>
</html>
