<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Open Rota</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @font-face {
            font-family: "newFont";
            src: url("fonts/CooperHewitt-Book.otf");
            font-weight: normal;
            font-style: normal;
        }

        /* Modern consistent styling matching the app */
        body {
            font-family: "newFont", Arial, sans-serif;
            background: url("images/backg3.jpg") no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .register-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 500px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .register-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header h2 {
            color: #fd2b2b;
            margin-bottom: 10px;
            font-size: 2rem;
            font-weight: 700;
        }

        .form-header p {
            color: #666;
            font-size: 1rem;
            margin: 0;
        }

        .form-section {
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #fd2b2b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #fd2b2b;
            box-shadow: 0 0 0 3px rgba(253, 43, 43, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            background: white;
            cursor: pointer;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            box-sizing: border-box;
        }

        .form-select:focus {
            outline: none;
            border-color: #fd2b2b;
            box-shadow: 0 0 0 3px rgba(253, 43, 43, 0.1);
        }

        .form-text {
            display: block;
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 5px;
            line-height: 1.4;
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 25px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #fd2b2b;
            margin-top: 2px;
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

        .btn-primary:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 43, 43, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            margin-top: 15px;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
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

        .text-center {
            text-align: center;
        }

        .login-link {
            color: #fd2b2b;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link:hover {
            text-decoration: underline;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .register-container {
                margin: 10px;
                padding: 30px 20px;
            }

            .form-header h2 {
                font-size: 1.5rem;
            }
        }

        .loading-spinner {
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
    </style>
</head>

<body style="background: url('images/backg3.jpg') no-repeat center center fixed; background-size: cover;">
    <div class="register-container">
        <!-- Logo Header -->
        <div class="logo-header">
            <div class="logo">Open Rota</div>
        </div>

        <div class="form-header">
            <h2><i class="fas fa-user-plus"></i> Create Your Account</h2>
            <p>Join Open Rota and start managing your shifts</p>
        </div>

        <div id="alertContainer"></div>

        <form id="registrationForm">
            <!-- Account Information -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-user"></i> Account Information
                </div>

                <div class="form-group">
                    <label for="username">Username * (Firstname space Lastname)</label>
                    <input type="text" id="username" name="username" class="form-control" required
                        placeholder="Enter your username" pattern="[A-Za-z0-9\s]{3,50}"
                        title="Username should be 3-50 characters">
                </div>

                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control" required
                        placeholder="Enter your email address">
                </div>
            </div>

            <!-- Branch Selection -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-building"></i> Work Location
                </div>

                <div class="form-group">
                    <label for="branch">Select Your Branch *</label>
                    <select id="branch" name="branch" class="form-select" required>
                        <option value="">Please select your work branch...</option>
                        <!-- Branches will be loaded dynamically -->
                    </select>
                    <small class="form-text">Choose the branch where you'll be working. You can change this later if
                        needed.</small>
                </div>
            </div>

            <!-- Account Security -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-lock"></i> Account Security
                </div>

                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" class="form-control" required
                        placeholder="Create a strong password" minlength="8">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required
                        placeholder="Confirm your password">
                </div>
            </div>

            <!-- Terms and Conditions -->
            <div class="form-section">
                <div class="section-title">
                    <i class="fas fa-file-contract"></i> Terms & Conditions
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">
                        I agree to the <a href="#" class="login-link">Terms & Conditions</a>
                        and <a href="#" class="login-link">Privacy Policy</a>
                    </label>
                </div>
            </div>

            <button type="submit" id="submitBtn" class="btn btn-primary">
                <span id="submitText"><i class="fas fa-paper-plane"></i> Send Verification Code</span>
                <span id="submitLoader" class="loading-spinner" style="display: none;"></span>
            </button>
        </form>

        <div class="text-center" style="margin-top: 25px; color: #666;">
            Already have an account? <a href="functions/login.php" class="login-link">Sign In</a>
        </div>
    </div>

    <!-- Use the latest stable EmailJS version -->
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4.4.1/dist/email.min.js"></script>
    <script type="text/javascript">
        console.log('🚀 Registration form loaded with latest EmailJS');

        // Hardcoded EmailJS config - consistent with verification page
        const EMAILJS_CONFIG = {
            PUBLIC_KEY: 'YGqPVvVnYPslW6od5',
            SERVICE_ID: 'service_u3l07kl',
            REGISTRATION_TEMPLATE_ID: 'template_azo6f7r',
            OTP_EXPIRY_MINUTES: 10,
            RESEND_COOLDOWN_SECONDS: 30
        };

        // Initialize EmailJS with new format as per documentation
        emailjs.init({
            publicKey: EMAILJS_CONFIG.PUBLIC_KEY,
        });
        console.log('✅ EmailJS initialized with new format');

        // Load branches on page load
        document.addEventListener('DOMContentLoaded', function () {
            loadBranches();
        });

        async function loadBranches() {
            try {
                const response = await fetch('functions/get_branches.php');
                const result = await response.json();

                if (result.success) {
                    const branchSelect = document.getElementById('branch');
                    const defaultOption = branchSelect.querySelector('option[value=""]');

                    // Clear existing options except the default
                    branchSelect.innerHTML = '<option value="">Please select your work branch...</option>';

                    // Add branch options
                    result.branches.forEach(branch => {
                        const option = document.createElement('option');
                        option.value = branch.id;
                        option.textContent = `${branch.name} (${branch.code})`;
                        option.title = branch.address;
                        branchSelect.appendChild(option);
                    });

                    console.log(`✅ Loaded ${result.branches.length} branches`);
                } else {
                    console.error('❌ Failed to load branches:', result.message);
                    showAlert('Failed to load branch options. Please refresh the page.', 'error');
                }
            } catch (error) {
                console.error('❌ Error loading branches:', error);
                showAlert('Failed to load branch options. Please refresh the page.', 'error');
            }
        }

        function showAlert(message, type = 'error') {
            const alertContainer = document.getElementById('alertContainer');
            alertContainer.innerHTML = `<div class="alert ${type}">${message}</div>`;

            // Auto-hide success/info alerts after 5 seconds
            if (type === 'success' || type === 'info') {
                setTimeout(() => {
                    if (alertContainer.innerHTML.includes(message)) {
                        alertContainer.innerHTML = '';
                    }
                }, 5000);
            }
        }

        function setLoading(loading = true) {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            const submitLoader = document.getElementById('submitLoader');

            submitBtn.disabled = loading;
            submitText.style.display = loading ? 'none' : 'inline';
            submitLoader.style.display = loading ? 'inline-block' : 'none';
        }

        function generateOTP() {
            return Math.floor(100000 + Math.random() * 900000).toString();
        }

        // Form submission handler
        document.getElementById('registrationForm').addEventListener('submit', async function (event) {
            event.preventDefault();
            console.log('🎯 Form submitted');

            // Get form data
            const formData = new FormData(event.target);
            const username = formData.get('username');
            const email = formData.get('email');
            const password = formData.get('password');
            const confirmPassword = formData.get('confirm_password');
            const branch = formData.get('branch');
            const terms = formData.get('terms');

            console.log('📋 Form data collected');

            // Validation
            if (!username || username.trim().length < 3) {
                showAlert('Username must be at least 3 characters long.');
                return;
            }

            if (!email || !email.includes('@')) {
                showAlert('Please enter a valid email address.');
                return;
            }

            if (!password || password.length < 8) {
                showAlert('Password must be at least 8 characters long.');
                return;
            }

            if (password !== confirmPassword) {
                showAlert('Passwords do not match.');
                return;
            }

            if (!branch) {
                showAlert('Please select your work branch.');
                return;
            }

            if (!terms) {
                showAlert('Please agree to the Terms & Conditions.');
                return;
            }

            setLoading(true);
            showAlert('Generating verification code...', 'info');

            try {
                // Generate OTP
                const otp = generateOTP();
                console.log('📧 Generated OTP:', otp);

                // Store registration data
                const registrationData = {
                    username: username.trim(),
                    email: email.trim(),
                    password: password,
                    branch_id: parseInt(branch),
                    otp: otp,
                    otpExpiry: Date.now() + (EMAILJS_CONFIG.OTP_EXPIRY_MINUTES * 60 * 1000) // Use config value
                };

                sessionStorage.setItem('registrationData', JSON.stringify(registrationData));
                console.log('💾 Registration data stored');

                showAlert('Sending verification email...', 'info');

                // Use EXACT same parameters and method as working raw test
                const templateParams = {
                    company_name: 'Open Rota',
                    user_email: email,
                    otp_code: otp,
                    to_email: email
                };

                console.log('📤 Sending email with exact working parameters:', templateParams);
                console.log('📤 Service:', EMAILJS_CONFIG.SERVICE_ID);
                console.log('📧 Template:', EMAILJS_CONFIG.REGISTRATION_TEMPLATE_ID);

                const response = await emailjs.send(
                    EMAILJS_CONFIG.SERVICE_ID,      // Use config value
                    EMAILJS_CONFIG.REGISTRATION_TEMPLATE_ID,     // Use config value
                    templateParams
                );

                console.log('📬 EmailJS response:', response);

                if (response && response.status === 200) {
                    console.log('✅ Email sent successfully');
                    showAlert('📧 Email sent successfully! Storing verification data...', 'success');

                    // Store OTP in database
                    const storeResponse = await fetch('functions/store_registration_otp.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            email: email,
                            otp: otp
                        })
                    });

                    if (storeResponse.ok) {
                        const storeData = await storeResponse.json();
                        if (storeData.success) {
                            showAlert('✅ Verification code sent! Check your email and redirecting...', 'success');

                            setTimeout(() => {
                                window.location.href = `verify_registration_otp.php?email=${encodeURIComponent(email)}`;
                            }, 3000);
                        } else {
                            throw new Error(storeData.message || 'Failed to store verification code');
                        }
                    } else {
                        throw new Error(`Server error: ${storeResponse.status}`);
                    }
                } else {
                    throw new Error(`EmailJS failed with status: ${response ? response.status : 'unknown'}`);
                }

            } catch (error) {
                console.error('❌ Registration error:', error);

                // Detailed error handling
                let errorMessage = 'Registration failed. ';

                if (error && error.status) {
                    if (error.status === 418) {
                        errorMessage = '❌ This should NOT happen since raw test worked!';
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
                    errorMessage += 'Unknown error occurred.';
                }

                showAlert(errorMessage, 'error');
                setLoading(false);
            }
        });

        // Add password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function () {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;

            if (confirmPassword && password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });


    </script>
</body>

</html>