<?php
// Simple OTP verification page for registration
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Verify Registration - Open Rota</title>
    <link rel="stylesheet" href="css/loginandregister.css">
    <link rel="stylesheet" href="css/dark_mode.css">
    <style>
        .center { max-width:420px; margin:60px auto; background:rgba(255,255,255,0.95); padding:28px; border-radius:12px; }
        .otp-inputs { display:flex; gap:8px; justify-content:center; margin:18px 0; }
        .otp-inputs input { width:48px; height:56px; font-size:24px; text-align:center; border-radius:8px; border:1px solid #ddd; }
        .btn { background:#ff0808;color:#fff;border:none;padding:12px 18px;border-radius:10px;cursor:pointer;width:100%;font-weight:600 }
        .alert { padding:12px;border-radius:8px;margin-bottom:12px }
        .alert.success{ background:#d4edda;color:#155724 }
        .alert.error{ background:#f8d7da;color:#721c24 }
    </style>
</head>
<body style="background: url('images/backg3.jpg') no-repeat center center fixed; background-size: cover;">
    <script>try{ if(localStorage.getItem('rota_theme')==='dark') document.documentElement.setAttribute('data-theme','dark'); }catch(e){}
</script>
    <div class="center">
        <h2 style="text-align:center;color:#ff0808;margin-top:0">🔐 Verify your email</h2>
        <p id="subtitle" style="text-align:center;color:#444">Enter the 6-digit code sent to your email</p>

        <div id="alertContainer"></div>

        <div class="otp-inputs" id="otpInputs">
            <input inputmode="numeric" pattern="\d" maxlength="1" />
            <input inputmode="numeric" pattern="\d" maxlength="1" />
            <input inputmode="numeric" pattern="\d" maxlength="1" />
            <input inputmode="numeric" pattern="\d" maxlength="1" />
            <input inputmode="numeric" pattern="\d" maxlength="1" />
            <input inputmode="numeric" pattern="\d" maxlength="1" />
        </div>

        <button id="verifyBtn" class="btn">Verify & Create Account</button>
        <div style="margin-top:12px;text-align:center"><a href="register_with_otp.php" style="color:#ff0808">Back to registration</a></div>
    </div>

    <script>
        function showAlert(msg, type='error'){
            const c = document.getElementById('alertContainer');
            c.innerHTML = `<div class="alert ${type==='success'?'success':'error'}">${msg}</div>`;
        }

        // Get email from query string
        function getQueryParam(name){
            const params = new URLSearchParams(window.location.search);
            return params.get(name);
        }

        const email = getQueryParam('email');
        if (!email) {
            showAlert('Missing email in URL. Please start registration again.', 'error');
            document.getElementById('verifyBtn').disabled = true;
        } else {
            document.getElementById('subtitle').textContent = `Enter the 6-digit code sent to ${decodeURIComponent(email)}`;
        }

        const inputs = Array.from(document.querySelectorAll('#otpInputs input'));
        inputs.forEach((inp, idx) => {
            inp.addEventListener('input', (e)=>{
                const v = e.target.value.replace(/\D/g,'');
                e.target.value = v;
                if (v && idx < inputs.length-1) inputs[idx+1].focus();
            });
            inp.addEventListener('keydown', (e)=>{
                if (e.key === 'Backspace' && !e.target.value && idx>0) inputs[idx-1].focus();
            });
        });

        document.getElementById('verifyBtn').addEventListener('click', async ()=>{
            const otp = inputs.map(i=>i.value).join('');
            if (otp.length !== 6) { showAlert('Please enter the full 6-digit code'); return; }
            // Load registrationData from localStorage first, fallback to sessionStorage
            const regRaw = localStorage.getItem('registrationData') || sessionStorage.getItem('registrationData');
            if (!regRaw) { showAlert('Registration data not found in this browser session. Please re-register.', 'error'); return; }
            let registrationData;
            try { registrationData = JSON.parse(regRaw); } catch (e) { showAlert('Corrupt registration data. Please re-register.','error'); return; }

            // Build payload
            const payload = { email: decodeURIComponent(email), otp: otp, registrationData };

            document.getElementById('verifyBtn').disabled = true;
            showAlert('Verifying...', 'success');

            try {
                const resp = await fetch('functions/verify_and_create_account.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await resp.json();
                if (data.success) {
                    showAlert(data.message || 'Account created. Redirecting...', 'success');
                    // Clear registrationData from both storages
                    try { localStorage.removeItem('registrationData'); } catch (e) {}
                    try { sessionStorage.removeItem('registrationData'); } catch (e) {}
                    setTimeout(()=>{ window.location.href = data.redirect_url || 'index.php?registered=1'; }, 1200);
                } else {
                    showAlert(data.message || 'Verification failed', 'error');
                    document.getElementById('verifyBtn').disabled = false;
                }

            } catch (err) {
                console.error(err);
                showAlert('Server error. Please try again later.', 'error');
                document.getElementById('verifyBtn').disabled = false;
            }
        });
    </script>
</body>
</html>
