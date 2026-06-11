<?php include __DIR__ . '/../private/functions.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy | AB Chem India</title>
    <meta name="description" content="How AB Chem India collects, uses, stores and protects your personal information — compliant with the DPDP Act 2023 and the IT Act 2000.">
    <meta name="robots" content="index, follow">
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" type="image/png" href="/logo.png">
</head>
<body>
<?php include 'header.php'; ?>
<main style="max-width:900px; margin:40px auto; padding:0 20px;">
    <h1 style="color:var(--primary); margin-bottom:8px; text-align:center;">Privacy Policy</h1>
    <p style="text-align:center; color:var(--muted); margin-bottom:32px;">Last updated: <?= date('F j, Y') ?></p>

    <div style="background:var(--surface); padding:32px; border-radius:var(--radius); margin-bottom:24px; border:1px solid var(--border); line-height:1.8; color:var(--muted);">
        <h2 style="color:var(--primary); margin-bottom:12px;">1. Who We Are</h2>
        <p>AB Chem India ("AB Chem", "we", "us", "our") is a partnership firm operating the website <strong>www.abchem.co.in</strong> with its principal place of business at Balanagar, Hyderabad, Telangana, India. This Privacy Policy explains how we collect, use, disclose and safeguard personal information when you visit the Website, create an account or place an order. We are the "Data Fiduciary" under the Digital Personal Data Protection Act, 2023 ("DPDP Act") for the personal data described below.</p>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">2. Information We Collect</h2>
        <p><strong>(a) Information you provide directly:</strong></p>
        <ul style="padding-left:20px;">
            <li>Identification &amp; contact data — name, organisation, designation, email address, phone number, GSTIN, shipping and billing address.</li>
            <li>Account credentials — username, password (stored hashed), security questions and two-factor authentication secrets.</li>
            <li>Order &amp; enquiry data — products quoted/ordered, intended end-use declarations, custom-synthesis briefs, uploaded attachments.</li>
            <li>Payment data — when a payment gateway is enabled, transaction reference, payment method and bank-confirmation status. <strong>We do not store full card numbers, CVV or net-banking credentials</strong> on our servers; these are handled by PCI-DSS-compliant payment processors.</li>
            <li>Communications — emails, contact-form submissions, chat or call records.</li>
        </ul>
        <p><strong>(b) Information collected automatically:</strong></p>
        <ul style="padding-left:20px;">
            <li>Device &amp; usage data — IP address, browser and OS version, referrer, pages viewed, search terms, time spent, click-stream.</li>
            <li>Cookies and similar technologies — see section 7 below.</li>
            <li>Log files maintained for security, fraud prevention and statutory record-keeping.</li>
        </ul>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">3. How We Use Your Information</h2>
        <ul style="padding-left:20px;">
            <li>To process quotations, orders, invoices, payments, shipments and after-sales support;</li>
            <li>To verify buyer identity and intended end-use, as required by chemical and pharmaceutical-supply regulations;</li>
            <li>To maintain your account, respond to enquiries and provide customer service;</li>
            <li>To send transactional emails (order confirmations, dispatch notifications, document delivery);</li>
            <li>To send marketing or product-update communications, where you have opted in (you may unsubscribe at any time);</li>
            <li>To operate, secure and improve the Website — including debugging, fraud prevention, and analytics;</li>
            <li>To comply with applicable law, court orders, tax filings and statutory record-retention obligations.</li>
        </ul>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">4. Legal Basis for Processing</h2>
        <p>We process your personal data on the lawful basis of: (i) <strong>your consent</strong>, given when you submit a form, create an account or accept cookies; (ii) <strong>performance of a contract</strong> to which you are a party; (iii) <strong>compliance with a legal obligation</strong>; and (iv) our <strong>legitimate business interests</strong> in operating, securing and improving our services, where these are not overridden by your rights.</p>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">5. How We Share Your Information</h2>
        <p>We do not sell your personal data. We share it only with:</p>
        <ul style="padding-left:20px;">
            <li><strong>Logistics partners</strong> (e.g. DHL, FedEx, BlueDart, India Post and freight forwarders) — for delivery and customs clearance;</li>
            <li><strong>Payment processors</strong> — for processing online payments;</li>
            <li><strong>Hosting, email and analytics providers</strong> — under contractual confidentiality obligations;</li>
            <li><strong>Professional advisors</strong> — auditors, lawyers and tax consultants, on a need-to-know basis;</li>
            <li><strong>Government authorities</strong> — when required by law, court order or to protect our rights.</li>
        </ul>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">6. International Transfers</h2>
        <p>If you place an international order, your address and shipment details will be transferred to carriers, customs brokers and the destination country's authorities to enable delivery. Where data is transferred outside India, we apply contractual safeguards consistent with the DPDP Act and Government of India notifications.</p>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">7. Cookies &amp; Tracking</h2>
        <p>The Website uses strictly-necessary cookies (session, cart, CSRF token, theme preference) and may use analytics cookies to understand aggregated usage patterns. You can disable cookies in your browser, but some features — such as the shopping cart, login session and structure-search — may not function. We do not use cross-site advertising trackers.</p>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">8. Data Retention</h2>
        <p>We retain personal data only for as long as necessary to fulfil the purposes described in this Policy and to comply with our legal, accounting and regulatory obligations. Order, invoice and tax records are typically retained for at least <strong>eight years</strong> as required by Indian tax and company law. Marketing-list data is retained until you withdraw consent. Server logs are retained for up to twelve months for security purposes.</p>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">9. Security</h2>
        <p>We implement reasonable security practices and procedures in line with Rule 8 of the IT (Reasonable Security Practices) Rules, 2011 and ISO/IEC 27001 controls — including HTTPS/TLS encryption, hashed-and-salted password storage, role-based access control, server-side input validation, CSRF protection, secure-cookie flags, off-site backups, and two-factor authentication for administrative accounts. However, no transmission over the internet can be guaranteed to be 100% secure, and you provide personal data at your own risk.</p>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">10. Your Rights</h2>
        <p>Subject to the DPDP Act and other applicable laws, you have the right to:</p>
        <ul style="padding-left:20px;">
            <li>Access a summary of the personal data we process about you;</li>
            <li>Request correction or updating of inaccurate or incomplete data;</li>
            <li>Request erasure of personal data that is no longer necessary;</li>
            <li>Withdraw consent for processing or marketing at any time;</li>
            <li>Nominate another individual to exercise your rights in case of death or incapacity;</li>
            <li>Lodge a grievance with our Grievance Officer (see section 12) and, if unsatisfied, with the Data Protection Board of India.</li>
        </ul>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">11. Children's Privacy</h2>
        <p>The Website is not intended for and is not directed at children under 18. We do not knowingly collect personal data from children. If you believe a child has provided us personal data, please contact us so we can delete it.</p>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">12. Grievance Officer</h2>
        <p>In accordance with the Information Technology Act, 2000 and Rule 5(9) of the IT (Reasonable Security Practices) Rules, 2011, the contact details of our Grievance Officer are:</p>
        <p style="margin-left:16px;">
            <strong>Grievance Officer</strong><br>
            AB Chem India<br>
            Balanagar, Hyderabad, Telangana, India<br>
            Email: <a href="mailto:connect@abchem.co.in" style="color:var(--primary);">connect@abchem.co.in</a><br>
            Response time: within thirty (30) days of receipt.
        </p>

        <h2 style="color:var(--primary); margin-top:24px; margin-bottom:12px;">13. Changes to this Policy</h2>
        <p>We may update this Privacy Policy from time to time. The updated version will be posted on this page with a revised "Last updated" date. Material changes will be communicated by email or a prominent notice on the Website.</p>
    </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>
