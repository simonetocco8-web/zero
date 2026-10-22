<!DOCTYPE html>
<html lang="it">
<head><meta charset="utf-8"><title>Nuova password temporanea</title></head>
<body>
    <h1>Nuova password temporanea</h1>
    <p>L’amministrazione di ZeroMagazzino ha effettuato un ripristino della password del tuo account.</p>
    <p>Email dell’account: {{ $accountEmail }}</p>
    <p>Password temporanea: <strong>{{ $temporaryPassword }}</strong></p>
    <p><a href="{{ $loginUrl }}">Accedi a ZeroMagazzino</a></p>
    <p>Ti consigliamo di modificare la password subito dopo l’accesso.</p>
    <p>Se non ti aspettavi questo ripristino, contatta l’assistenza ZeroMagazzino.</p>
</body>
</html>
