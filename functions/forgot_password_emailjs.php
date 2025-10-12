<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Open Rota</title>
    <link rel="stylesheet" href="../css/loginandregister.css">
    <link rel="stylesheet" href="../css/forgot_password.css">
    <link rel="stylesheet" href="../css/dark_mode.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- EmailJS SDK -->
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
    <!-- EmailJS Configuration -->
    <script src="../js/emailjs-config.js"></script>
    <style>
        /* OTP-specific styles not in main CSS */
        .otp-container {
            display: none;
            margin-top: 20px;
        }

        .otp-inputs {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0;
            flex-wrap: wrap;
        }

        .otp-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 1.2rem;
            font-family: 'newFont', sans-serif;
            border: 2px solid rgba(253, 43, 43, 0.3);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.9);
            color: #333;
            transition: all 0.3s ease;
            outline: none;
        }

        .otp-input:focus {
            border-color: #fd2b2b;
            background: #fff;
            box-shadow: 0 0 15px rgba(253, 43, 43, 0.2);
            transform: translateY(-2px);
        }

        .resend-btn {
            background: transparent;
            border: none;
            color: #fd2b2b;
            cursor: pointer;
            text-decoration: underline;
            font-family: 'newFont', sans-serif;
            font-weight: 500;
            margin-top: 15px;
            padding: 10px;
            transition: all 0.3s ease;
        }

        .resend-btn:hover {
            color: #e91e63;
            transform: translateY(-1px);
        }

        .timer {
            color: #666;
            font-size: 14px;
            margin-top: 10px;
            font-family: 'newFont', sans-serif;
        }

        /* Custom background for this page */
        body {
            background: url("../images/backg3.jpg") no-repeat center center fixed;
            background-size: cover;
        }
    </style>
    <script>try { if (localStorage.getItem('rota_theme') === 'dark') document.documentElement.setAttribute('data-theme', 'dark'); } catch (e) { }
    </script>

    @media (max-width: 480px) {
    .otp-inputs {
    gap: 8px;
    }

    .otp-input {
    width: 45px;
    height: 45px;
    font-size: 1.1rem;
    }
    }
    </style>
</head>

<body>
    <div class="auth-container">
        <!-- Logo Header -->
        <div class="logo-header">
            <div class="logo"><img src="../images/new logo.png" alt="Open Rota" style="height: 60px;"></div>
        </div>

        <div class="forgot-header">
            <h1><i class="fas fa-unlock-alt"></i> Forgot Password</h1>
            <p>Reset your password using email verification</p>
        </div>

        <!-- Email Form -->
        <div id="email-form">
            <p class="text-center mb-20">Enter your email address to receive a 6-digit OTP code</p>
            <form id="emailForm" class="card__form">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" class="form-control" placeholder="Enter your email address" required>
                </div>
                <button type="submit" id="sendOtpBtn" class="btn-primary">
                    <i class="fas fa-paper-plane"></i> Send OTP
                </button>
            </form>
        </div>

        <!-- OTP Verification Form -->
        <div id="otp-container" class="otp-container">
            <div class="forgot-header">
                <h2><i class="fas fa-shield-alt"></i> Verify OTP</h2>
                <p>Enter the 6-digit code sent to your email</p>
            </div>
            <div class="otp-inputs">
                <input type="text" class="otp-input" maxlength="1" pattern="\d">
                <input type="text" class="otp-input" maxlength="1" pattern="\d">
                <input type="text" class="otp-input" maxlength="1" pattern="\d">
                <input type="text" class="otp-input" maxlength="1" pattern="\d">
                <input type="text" class="otp-input" maxlength="1" pattern="\d">
                <input type="text" class="otp-input" maxlength="1" pattern="\d">
            </div>
            <button id="verifyOtpBtn" class="btn-primary">
                <i class="fas fa-check"></i> Verify OTP
            </button>
            <button class="resend-btn" id="resendBtn">
                <i class="fas fa-redo"></i> Resend Code
            </button>
            <div class="timer" id="timer"></div>
        </div>

        <!-- Messages -->
        <div id="message-container"></div>

        <button class="btn-secondary" type="button" onclick="window.location.href='../functions/login.php';">
            <i class="fas fa-arrow-left"></i> Back to Login
        </button>
    </div>

    <script>
        // EmailJS Configuration (Update these in js/emailjs-config.js)
        const EMAILJS_SERVICE_ID = EMAILJS_CONFIG.SERVICE_ID;
        const EMAILJS_TEMPLATE_ID = EMAILJS_CONFIG.TEMPLATE_ID;
        const EMAILJS_PUBLIC_KEY = EMAILJS_CONFIG.PUBLIC_KEY;

        // Initialize EmailJS
        emailjs.init(EMAILJS_PUBLIC_KEY);

        // Global variables
        let generatedOTP = '';
        let userEmail = '';
        let resendTimer = 0;
        let timerInterval;

        // DOM elements
        const emailForm = document.getElementById('emailForm');
        const otpContainer = document.getElementById('otp-container');
        const emailFormContainer = document.getElementById('email-form');
        const messageContainer = document.getElementById('message-container');
        const otpInputs = document.querySelectorAll('.otp-input');
        const sendOtpBtn = document.getElementById('sendOtpBtn');
        const verifyOtpBtn = document.getElementById('verifyOtpBtn');
        const resendBtn = document.getElementById('resendBtn');
        const timerElement = document.getElementById('timer');

        // Generate 6-digit OTP
        function generateOTP() {
            const length = EMAILJS_CONFIG.OTP_LENGTH;
            const min = Math.pow(10, length - 1);
            const max = Math.pow(10, length) - 1;
            return Math.floor(min + Math.random() * (max - min + 1)).toString();
        }

        // Show message
        function showMessage(message, type = 'error') {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
            const icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            messageContainer.innerHTML = `<div class="${alertClass}"><i class="${icon}"></i> ${message}</div>`;
            setTimeout(() => {
                messageContainer.innerHTML = '';
            }, 5000);
        }

        // Send OTP via EmailJS
        async function sendOTP(email, otp) {
            try {
                const templateParams = {
                    to_email: email,
                    otp_code: otp,
                    company_name: 'Open Rota'
                };

                const response = await emailjs.send(
                    EMAILJS_SERVICE_ID,
                    EMAILJS_TEMPLATE_ID,
                    templateParams
                );

                return response.status === 200;
            } catch (error) {
                console.error('EmailJS error:', error);
                return false;
            }
        }

        // Verify email exists in database
        async function verifyEmail(email) {
            try {
                const response = await fetch('../functions/verify_email_exists.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ email: email })
                });
                const data = await response.json();
                return data.exists;
            } catch (error) {
                console.error('Email verification error:', error);
                return false;
            }
        }

        // Start resend timer
        function startResendTimer() {
            resendTimer = EMAILJS_CONFIG.RESEND_COOLDOWN_SECONDS; // From config
            resendBtn.disabled = true;
            resendBtn.textContent = `Resend Code (${resendTimer}s)`;

            timerInterval = setInterval(() => {
                resendTimer--;
                if (resendTimer > 0) {
                    resendBtn.textContent = `Resend Code (${resendTimer}s)`;
                    timerElement.textContent = `You can request a new code in ${resendTimer} seconds`;
                } else {
                    clearInterval(timerInterval);
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend Code';
                    timerElement.textContent = '';
                }
            }, 1000);
        }

        // Handle OTP input navigation
        otpInputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                if (e.target.value.length === 1 && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && e.target.value === '' && index > 0) {
                    otpInputs[index - 1].focus();
                }
            });
        });

        // Email form submission
        emailForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const email = document.getElementById('email').value.trim();

            if (!email) {
                showMessage('Please enter your email address');
                return;
            }

            sendOtpBtn.classList.add('loading');
            sendOtpBtn.textContent = 'Sending...';

            // Check if email exists in database
            const emailExists = await verifyEmail(email);

            if (!emailExists) {
                showMessage('No account found with this email address');
                sendOtpBtn.classList.remove('loading');
                sendOtpBtn.textContent = 'Send OTP';
                return;
            }

            // Generate OTP and send email
            generatedOTP = generateOTP();
            userEmail = email;

            const emailSent = await sendOTP(email, generatedOTP);

            if (emailSent) {
                showMessage('OTP sent successfully! Check your email.', 'success');
                emailFormContainer.style.display = 'none';
                otpContainer.style.display = 'block';
                startResendTimer();
                otpInputs[0].focus();
            } else {
                showMessage('Failed to send OTP. Please try again.');
            }

            sendOtpBtn.classList.remove('loading');
            sendOtpBtn.textContent = 'Send OTP';
        });

        // OTP verification
        verifyOtpBtn.addEventListener('click', async () => {
            const enteredOTP = Array.from(otpInputs).map(input => input.value).join('');

            if (enteredOTP.length !== EMAILJS_CONFIG.OTP_LENGTH) {
                showMessage(`Please enter all ${EMAILJS_CONFIG.OTP_LENGTH} digits`);
                return;
            }

            verifyOtpBtn.classList.add('loading');
            verifyOtpBtn.textContent = 'Verifying...';

            if (enteredOTP === generatedOTP) {
                showMessage('OTP verified successfully! Redirecting...', 'success');

                // Store email in session and redirect to reset password
                try {
                    const response = await fetch('../functions/set_reset_session.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ email: userEmail })
                    });

                    if (response.ok) {
                        setTimeout(() => {
                            window.location.href = '../functions/reset_password.php';
                        }, 1500);
                    } else {
                        showMessage('Session error. Please try again.');
                    }
                } catch (error) {
                    showMessage('Error setting session. Please try again.');
                }
            } else {
                showMessage('Invalid OTP. Please try again.');
                // Clear OTP inputs
                otpInputs.forEach(input => input.value = '');
                otpInputs[0].focus();
            }

            verifyOtpBtn.classList.remove('loading');
            verifyOtpBtn.textContent = 'Verify OTP';
        });

        // Resend OTP
        resendBtn.addEventListener('click', async () => {
            if (resendTimer > 0) return;

            resendBtn.classList.add('loading');
            resendBtn.textContent = 'Sending...';

            generatedOTP = generateOTP();
            const emailSent = await sendOTP(userEmail, generatedOTP);

            if (emailSent) {
                showMessage('New OTP sent successfully!', 'success');
                startResendTimer();
                // Clear previous OTP inputs
                otpInputs.forEach(input => input.value = '');
                otpInputs[0].focus();
            } else {
                showMessage('Failed to send OTP. Please try again.');
            }

            resendBtn.classList.remove('loading');
            resendBtn.textContent = 'Resend Code';
        });
    </script>
    <script src="../js/darkmode.js"></script>
</body>

</html>