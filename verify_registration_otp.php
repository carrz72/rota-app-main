<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email - Open Rota</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4.4.1/dist/email.min.js"></script>
    <script>
        // Hardcoded EmailJS config - same as working registration form
        const EMAILJS_CONFIG = {
            PUBLIC_KEY: 'YGqPVvVnYPslW6od5',
            SERVICE_ID: 'service_u3l07kl',
            REGISTRATION_TEMPLATE_ID: 'template_azo6f7r',
            OTP_EXPIRY_MINUTES: 10,
            RESEND_COOLDOWN_SECONDS: 30
        };
    </script>
    <style>
        @font-face {
            font-family: "newFont";
            src: url("fonts/CooperHewitt-Book.otf");
            font-weight: normal;
            font-style: normal;
        }

        body {
            font-family: "newFont", Arial, sans-serif;
            background: url('images/backg3.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .verify-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 550px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .verify-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 40px;
            padding: 0;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin: 0 15px;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .step.active .step-number {
            background: #fd2b2b;
            color: white;
            box-shadow: 0 0 0 4px rgba(253, 43, 43, 0.2);
        }

        .step.completed .step-number {
            background: #28a745;
            color: white;
        }

        .step.inactive .step-number {
            background: #e2e8f0;
            color: #64748b;
        }

        .step-text {
            font-size: 12px;
            font-weight: 600;
            margin-top: 5px;
        }

        .step.active .step-text {
            color: #fd2b2b;
        }

        .step.completed .step-text {
            color: #28a745;
        }

        .step.inactive .step-text {
            color: #64748b;
        }

        .step-connector {
            width: 60px;
            height: 3px;
            background: #e2e8f0;
            margin: 0 10px;
            margin-top: 20px;
            border-radius: 2px;
        }

        .step.completed+.step-connector {
            background: #28a745;
        }

        .verify-header {
            margin-bottom: 30px;
        }

        .verify-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #fd2b2b;
        }

        .verify-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
        }

        .verify-subtitle {
            font-size: 1rem;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .email-display {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 2px solid #fd2b2b;
            position: relative;
        }

        .email-display::before {
            content: '';
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 5px 10px;
            border-radius: 50%;
            font-size: 18px;
        }

        .email-label {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .email-value {
            font-size: 1.125rem;
            font-weight: 700;
            color: #333;
            word-break: break-all;
        }

        .otp-input-container {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin: 40px 0;
        }

        .otp-input {
            width: 60px;
            height: 70px;
            text-align: center;
            border: 3px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1.5rem;
            font-weight: bold;
            color: #333;
            background: white;
            transition: all 0.3s ease;
            font-family: 'Courier New', monospace;
        }

        .otp-input:focus {
            outline: none;
            border-color: #fd2b2b;
            box-shadow: 0 0 0 4px rgba(253, 43, 43, 0.1);
            transform: scale(1.05);
        }

        .otp-input.filled {
            border-color: #28a745;
            background: #f0fff4;
            color: #28a745;
        }

        .otp-input.error {
            border-color: #dc3545;
            background: #fff5f5;
            color: #dc3545;
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-5px);
            }

            75% {
                transform: translateX(5px);
            }
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 15px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn-primary {
            background: #fd2b2b;
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 43, 43, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover:not(:disabled) {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .resend-section {
            margin: 30px 0;
            padding: 25px;
            background: #f8f9fa;
            border-radius: 15px;
            border: 2px solid #dee2e6;
        }

        .resend-text {
            font-size: 1rem;
            color: #666;
            margin-bottom: 15px;
            font-weight: 500;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f1aeb5;
        }

        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        }

        .resend-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #5a6268, #495057);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }

        .resend-btn:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            transform: none;
        }

        .resend-cooldown {
            margin-top: 15px;
            font-size: 14px;
            color: #6c757d;
            font-weight: 500;
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            border: 2px solid #f5c6cb;
            color: #721c24;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: 2px solid #c3e6cb;
            color: #155724;
        }

        .alert-info {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            border: 2px solid #bee5eb;
            color: #0c5460;
        }

        .back-link {
            text-align: center;
            margin-top: 30px;
        }

        .back-link a {
            color: #ff0808;
            text-decoration: none;

            /* Responsive design */
            @media (max-width: 768px) {
                .verify-container {
                    margin: 10px;
                    padding: 30px 20px;
                }

                .otp-input-container {
                    gap: 8px;
                }

                .otp-input {
                    width: 45px;
                    height: 55px;
                    font-size: 1.25rem;
                }

                .verify-title {
                    font-size: 1.5rem;
                }
            }

            @media (max-width: 480px) {
                .otp-input-container {
                    gap: 5px;
                }

                .otp-input {
                    width: 40px;
                    height: 50px;
                    font-size: 1.125rem;
                }
            }
    </style>
</head>

<body>
    <div class="verify-container">
        <!-- Logo Header -->
        <div class="logo-header">
            <div class="logo">Open Rota</div>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step completed">
                <div class="step-number"><i class="fas fa-check"></i></div>
                <div class="step-text">Enter Details</div>
            </div>
            <div class="step-connector"></div>
            <div class="step active">
                <div class="step-number">2</div>
                <div class="step-text">Verify Email</div>
            </div>
            <div class="step-connector"></div>
            <div class="step inactive">
                <div class="step-number">3</div>
                <div class="step-text">Complete</div>
            </div>
        </div>

        <!-- Header Section -->
        <div class="verify-header">
            <div class="verify-icon"><i class="fas fa-envelope"></i></div>
            <h1 class="verify-title">Check Your Email</h1>
            <p class="verify-subtitle">
                We've sent a 6-digit verification code to your email address.
                Enter the code below to complete your registration and join Open Rota!
            </p>
        </div>

        <!-- Email Display -->
        <div class="email-display">
            <div class="email-label">Verification code sent to</div>
            <div class="email-value" id="displayEmail"></div>
        </div>

        <!-- Alert Container -->
        <div id="alertContainer"></div>

        <form id="otpForm" onsubmit="handleOTPVerification(event)">
            <div class="otp-input-container">
                <input type="text" class="otp-input" maxlength="1" id="otp1" oninput="handleOTPInput(this, 1)"
                    onkeydown="handleOTPKeydown(this, event)" autocomplete="off">
                <input type="text" class="otp-input" maxlength="1" id="otp2" oninput="handleOTPInput(this, 2)"
                    onkeydown="handleOTPKeydown(this, event)" autocomplete="off">
                <input type="text" class="otp-input" maxlength="1" id="otp3" oninput="handleOTPInput(this, 3)"
                    onkeydown="handleOTPKeydown(this, event)" autocomplete="off">
                <input type="text" class="otp-input" maxlength="1" id="otp4" oninput="handleOTPInput(this, 4)"
                    onkeydown="handleOTPKeydown(this, event)" autocomplete="off">
                <input type="text" class="otp-input" maxlength="1" id="otp5" oninput="handleOTPInput(this, 5)"
                    onkeydown="handleOTPKeydown(this, event)" autocomplete="off">
                <input type="text" class="otp-input" maxlength="1" id="otp6" oninput="handleOTPInput(this, 6)"
                    onkeydown="handleOTPKeydown(this, event)" autocomplete="off">
            </div>

            <button type="submit" class="btn btn-primary" id="verifyBtn" style="margin: 30px 0;">
                <span id="verifyText"><i class="fas fa-check"></i> Verify & Create Account</span>
                <span id="verifyLoader" class="loading-spinner" style="display: none;"></span>
            </button>
        </form>

        <!-- Resend Section -->
        <div class="resend-section">
            <div class="resend-text">Didn't receive the code?</div>
            <button class="btn btn-secondary" id="resendBtn">
                <span id="resendText"><i class="fas fa-redo"></i> Resend Code</span>
                <span id="resendLoader" class="loading-spinner" style="display: none;"></span>
            </button>
            <div id="resendCooldown" class="resend-cooldown" style="display: none;">
                Please wait <span id="resendTimer">30</span> seconds before requesting a new code
            </div>
        </div>

        <!-- Back Link -->
        <div style="text-align: center; margin-top: 25px;">
            <a href="register_with_otp.php" style="color: #fd2b2b; text-decoration: none; font-weight: 600;">
                <i class="fas fa-arrow-left"></i> Back to Registration
            </a>
        </div>

        <script>
            // Initialize EmailJS with new format as per documentation
            emailjs.init({
                publicKey: EMAILJS_CONFIG.PUBLIC_KEY,
            });
            console.log('✅ EmailJS initialized for verification page with new format');

            let userEmail = '';
            let resendCooldownTimer = null;

            function showAlert(message, type = 'error') {
                const alertContainer = document.getElementById('alertContainer');
                alertContainer.innerHTML = `
                <div class="alert alert-${type}">
                    ${message}
                </div>
            `;

                // Auto-hide success/info alerts after 5 seconds
                if (type === 'success' || type === 'info') {
                    setTimeout(() => {
                        alertContainer.innerHTML = '';
                    }, 5000);
                }
            }

            function handleOTPInput(input, position) {
                const value = input.value.replace(/[^0-9]/g, '');
                input.value = value;

                if (value) {
                    input.classList.add('filled');
                    input.classList.remove('error');

                    // Move to next input
                    if (position < 6) {
                        document.getElementById(`otp${position + 1}`).focus();
                    }

                    // Check if all inputs are filled
                    checkOTPComplete();
                } else {
                    input.classList.remove('filled');
                }
            }

            function handleOTPKeydown(input, event) {
                if (event.key === 'Backspace' && !input.value) {
                    const position = parseInt(input.id.replace('otp', ''));
                    if (position > 1) {
                        document.getElementById(`otp${position - 1}`).focus();
                    }
                }
            }

            function checkOTPComplete() {
                let otp = '';
                for (let i = 1; i <= 6; i++) {
                    otp += document.getElementById(`otp${i}`).value;
                }

                if (otp.length === 6) {
                    // Auto-submit after a short delay
                    setTimeout(() => {
                        document.getElementById('otpForm').dispatchEvent(new Event('submit'));
                    }, 500);
                }
            }

            function clearOTPInputs() {
                for (let i = 1; i <= 6; i++) {
                    const input = document.getElementById(`otp${i}`);
                    input.value = '';
                    input.classList.remove('filled', 'error');
                }
                document.getElementById('otp1').focus();
            }

            function markOTPError() {
                for (let i = 1; i <= 6; i++) {
                    document.getElementById(`otp${i}`).classList.add('error');
                }
            }

            function getOTPValue() {
                let otp = '';
                for (let i = 1; i <= 6; i++) {
                    otp += document.getElementById(`otp${i}`).value;
                }
                return otp;
            }

            function generateOTP() {
                return Math.floor(100000 + Math.random() * 900000).toString();
            }

            function startResendCooldown() {
                const resendBtn = document.getElementById('resendBtn');
                const resendCooldown = document.getElementById('resendCooldown');
                const resendTimer = document.getElementById('resendTimer');

                let timeLeft = EMAILJS_CONFIG.RESEND_COOLDOWN_SECONDS; // Use config value
                resendBtn.disabled = true;
                resendCooldown.style.display = 'block';
                resendTimer.textContent = timeLeft; // Set initial display value

                resendCooldownTimer = setInterval(() => {
                    timeLeft--;
                    resendTimer.textContent = timeLeft;

                    if (timeLeft <= 0) {
                        clearInterval(resendCooldownTimer);
                        resendBtn.disabled = false;
                        resendCooldown.style.display = 'none';
                    }
                }, 1000);
            }

            let isResending = false; // Add debounce flag

            async function resendOTP() {
                console.log('🔄 Resend OTP function called');

                // Prevent duplicate calls
                if (isResending) {
                    console.log('⚠️ Resend already in progress, ignoring duplicate call');
                    return;
                }

                isResending = true;
                console.log('📧 Current userEmail:', userEmail);

                const resendBtn = document.getElementById('resendBtn');
                const resendText = document.getElementById('resendText');
                const resendLoader = document.getElementById('resendLoader');

                // Check if userEmail is available
                if (!userEmail) {
                    console.error('❌ No userEmail available for resend');
                    showAlert('Error: Email address not found. Please return to registration.', 'error');
                    return;
                }

                resendBtn.disabled = true;
                resendText.style.display = 'none';
                resendLoader.style.display = 'inline-block';

                try {
                    const otp = generateOTP();
                    console.log('📧 Generated new OTP:', otp);

                    // Check session storage
                    let registrationData = JSON.parse(sessionStorage.getItem('registrationData'));
                    console.log('💾 Current registration data:', registrationData);

                    if (!registrationData) {
                        console.warn('⚠️ No registration data in session, creating minimal data');
                        registrationData = {
                            email: userEmail,
                            otp: otp
                        };
                    } else {
                        // Update session storage with new OTP
                        registrationData.otp = otp;
                    }

                    sessionStorage.setItem('registrationData', JSON.stringify(registrationData));

                    // Update local variables
                    otpExpiry = registrationData.otpExpiry;

                    // Send new OTP email using exact same format as working registration
                    const templateParams = {
                        company_name: 'Open Rota',
                        user_email: userEmail,
                        otp_code: otp,
                        to_email: userEmail
                    };

                    console.log('📤 Resending OTP with working parameters:', templateParams);
                    console.log('📤 Service:', EMAILJS_CONFIG.SERVICE_ID);
                    console.log('📧 Template:', EMAILJS_CONFIG.REGISTRATION_TEMPLATE_ID);
                    console.log('🔍 Template parameters details:');
                    console.log('  - company_name:', templateParams.company_name);
                    console.log('  - user_email:', templateParams.user_email);
                    console.log('  - otp_code:', templateParams.otp_code);
                    console.log('  - to_email:', templateParams.to_email);

                    const response = await emailjs.send(
                        EMAILJS_CONFIG.SERVICE_ID,          // Use config value
                        EMAILJS_CONFIG.REGISTRATION_TEMPLATE_ID,         // Use config value
                        templateParams
                    );

                    console.log('📬 EmailJS resend response:', response);

                    if (response && response.status === 200) {
                        console.log('✅ Email resent successfully');

                        // Update session storage with new OTP and expiry
                        sessionStorage.setItem('registrationData', JSON.stringify(registrationData));
                        console.log('💾 Updated session storage with new OTP');

                        showAlert('✅ New verification code sent to your email!', 'success');
                        clearOTPInputs();
                        startResendCooldown();
                        document.getElementById('verifyBtn').disabled = false;
                    } else {
                        throw new Error(`EmailJS failed with status: ${response ? response.status : 'unknown'}`);
                    }

                } catch (error) {
                    console.error('❌ Resend OTP error:', error);
                    console.error('❌ Error details:', {
                        message: error.message,
                        status: error.status,
                        text: error.text,
                        name: error.name,
                        stack: error.stack
                    });

                    let errorMessage = 'Failed to resend verification code. ';
                    if (error && error.status) {
                        if (error.status === 418) {
                            errorMessage += 'Template parameter mismatch.';
                        } else if (error.status === 401) {
                            errorMessage += 'Email service authentication failed.';
                        } else if (error.status === 404) {
                            errorMessage += 'Email template not found.';
                        } else {
                            errorMessage += `Email service error (${error.status}).`;
                        }
                    } else if (error && error.message) {
                        errorMessage += error.message;
                    } else {
                        errorMessage += 'Please try again or contact support.';
                    }

                    showAlert(errorMessage, 'error');
                } finally {
                    isResending = false; // Reset debounce flag
                    resendBtn.disabled = false;
                    resendText.style.display = 'inline';
                    resendLoader.style.display = 'none';
                }
            }

            async function handleOTPVerification(event) {
                event.preventDefault();

                const otp = getOTPValue();
                if (otp.length !== 6) {
                    showAlert('Please enter the complete 6-digit verification code.');
                    return;
                }

                const verifyBtn = document.getElementById('verifyBtn');
                const verifyText = document.getElementById('verifyText');
                const verifyLoader = document.getElementById('verifyLoader');

                verifyBtn.disabled = true;
                verifyText.style.display = 'none';
                verifyLoader.style.display = 'inline-block';

                try {
                    // Get registration data from session storage
                    const registrationData = JSON.parse(sessionStorage.getItem('registrationData'));
                    if (!registrationData) {
                        throw new Error('Registration session expired. Please start again.');
                    }

                    // Verify OTP and create account
                    const response = await fetch('functions/verify_and_create_account.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            email: userEmail,
                            otp: otp,
                            registrationData: registrationData
                        })
                    });

                    const result = await response.json();

                    if (result.success) {
                        // Clear session storage
                        sessionStorage.removeItem('registrationData');

                        showAlert('Account created successfully! Redirecting to login...', 'success');

                        // Use redirect URL from backend response
                        const redirectUrl = result.redirect_url || 'index.php?registered=1';

                        // Redirect to login page after 3 seconds
                        setTimeout(() => {
                            window.location.href = redirectUrl;
                        }, 3000);
                    } else {
                        if (result.message.includes('Invalid verification code')) {
                            markOTPError();
                            clearOTPInputs();
                        }
                        throw new Error(result.message);
                    }

                } catch (error) {
                    console.error('OTP verification error:', error);
                    showAlert(error.message || 'Verification failed. Please try again.');
                } finally {
                    verifyBtn.disabled = false;
                    verifyText.style.display = 'inline';
                    verifyLoader.style.display = 'none';
                }
            }

            // Initialize page
            document.addEventListener('DOMContentLoaded', function () {
                console.log('🚀 Verification page loaded');

                // Initialize display values based on config
                const resendTimerElement = document.getElementById('resendTimer');

                if (resendTimerElement) {
                    resendTimerElement.textContent = EMAILJS_CONFIG.RESEND_COOLDOWN_SECONDS;
                }

                // Get email from URL parameter
                const urlParams = new URLSearchParams(window.location.search);
                userEmail = urlParams.get('email');

                console.log('📧 Email from URL:', userEmail);
                console.log('🔗 Current URL:', window.location.href);

                if (!userEmail) {
                    console.error('❌ No email parameter in URL');
                    showAlert('Invalid access. Redirecting to registration...', 'error');
                    setTimeout(() => {
                        window.location.href = 'register_with_otp.php';
                    }, 3000);
                    return;
                }

                // Display email
                document.getElementById('displayEmail').textContent = userEmail;
                console.log('✅ Email displayed in UI');

                // Get registration data from session storage
                const registrationData = JSON.parse(sessionStorage.getItem('registrationData'));
                console.log('💾 Registration data from session:', registrationData);

                if (!registrationData) {
                    console.warn('⚠️ No registration data in session storage');
                    showAlert('Registration session expired. You can still request a new verification code.', 'info');
                    // Don't redirect immediately, allow user to try resend
                } else {
                    console.log('✅ Registration data found in session');
                }

                // Focus first input
                document.getElementById('otp1').focus();
                console.log('🎯 Focus set to first OTP input');

                // Start resend cooldown
                startResendCooldown();
                console.log('⏱️ Resend cooldown started');

                // Test resend button
                const resendBtn = document.getElementById('resendBtn');
                if (resendBtn) {
                    console.log('✅ Resend button found');
                    // Add click event listener as backup to onclick
                    resendBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        console.log('🔄 Resend button clicked via event listener');
                        resendOTP();
                    });
                } else {
                    console.error('❌ Resend button not found!');
                }

                console.log('🎉 Verification page initialization complete');
            });

            // Test function to verify EmailJS with exact same parameters as registration
            async function testEmailJS() {
                console.log('🧪 Testing EmailJS with exact registration parameters...');

                const testOTP = '123456';
                const testEmail = userEmail || 'test@example.com';

                const templateParams = {
                    company_name: 'Open Rota',
                    user_email: testEmail,
                    otp_code: testOTP,
                    to_email: testEmail
                };

                console.log('🧪 Test parameters:', templateParams);
                console.log('🧪 Service ID:', EMAILJS_CONFIG.SERVICE_ID);
                console.log('🧪 Template ID:', EMAILJS_CONFIG.REGISTRATION_TEMPLATE_ID);

                try {
                    const response = await emailjs.send(
                        EMAILJS_CONFIG.SERVICE_ID,
                        EMAILJS_CONFIG.REGISTRATION_TEMPLATE_ID,
                        templateParams
                    );

                    console.log('✅ Test successful:', response);
                    showAlert('Test email sent successfully!', 'success');
                } catch (error) {
                    console.error('❌ Test failed:', error);
                    showAlert(`Test failed: ${error.message || error.text || 'Unknown error'}`, 'error');
                }
            }
        </script>
</body>

</html>