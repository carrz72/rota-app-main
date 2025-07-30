<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Welcome Email - Open Rota</title>
    <!-- EmailJS SDK -->
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
    <!-- EmailJS Configuration -->
    <script src="../js/emailjs-config.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #fd2b2b;
            text-align: center;
        }

        .hidden {
            display: none;
        }

        .message {
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>📧 Welcome Email Sender</h1>
        <div id="message-container"></div>
    </div>

    <script>
        // Initialize EmailJS
        emailjs.init(EMAILJS_CONFIG.PUBLIC_KEY);

        // Function to send welcome email
        async function sendWelcomeEmail(userData) {
            try {
                const templateParams = {
                    user_name: userData.username,
                    user_email: userData.email,
                    company_name: 'Open Rota',
                    creation_date: new Date().toLocaleDateString(),
                    login_url: window.location.origin + '/rota-app-main/functions/login.php'
                };

                console.log('Sending welcome email with params:', templateParams);

                const response = await emailjs.send(
                    EMAILJS_CONFIG.SERVICE_ID,
                    EMAILJS_CONFIG.WELCOME_TEMPLATE_ID,
                    templateParams
                );

                return response.status === 200;
            } catch (error) {
                console.error('Welcome email error:', error);
                return false;
            }
        }

        // Check if we should send welcome email (called from registration)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('send_welcome')) {
            const userData = {
                username: urlParams.get('username'),
                email: urlParams.get('email')
            };

            if (userData.username && userData.email) {
                sendWelcomeEmail(userData).then(success => {
                    const messageContainer = document.getElementById('message-container');
                    if (success) {
                        messageContainer.innerHTML = `
                            <div class="message success">
                                ✅ Welcome email sent successfully to ${userData.email}!
                                <br><small>Redirecting to login page in 3 seconds...</small>
                            </div>
                        `;
                        setTimeout(() => {
                            window.location.href = 'login.php';
                        }, 3000);
                    } else {
                        messageContainer.innerHTML = `
                            <div class="message error">
                                ❌ Failed to send welcome email. Registration was successful, but email delivery failed.
                                <br><small>Redirecting to login page in 5 seconds...</small>
                            </div>
                        `;
                        setTimeout(() => {
                            window.location.href = 'login.php';
                        }, 5000);
                    }
                });
            }
        }

        // Export function for use in other files
        window.sendWelcomeEmail = sendWelcomeEmail;
    </script>
</body>

</html>