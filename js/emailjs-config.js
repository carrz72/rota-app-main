// EmailJS Configuration
// Update these values with your EmailJS account details

const EMAILJS_CONFIG = {
    SERVICE_ID: 'service_u3l07kl',        // From EmailJS Email Services
    TEMPLATE_ID: 'template_hcywjo6',      // Password Reset Template ID
    REGISTRATION_TEMPLATE_ID: 'template_azo6f7r', // Registration OTP Template ID (update this)
    PUBLIC_KEY: 'YGqPVvVnYPslW6od5',       // From EmailJS Account Settings

    // Template parameters (these match your EmailJS template variables)
    TEMPLATE_PARAMS: {
        TO_EMAIL: 'to_email',
        TO_NAME: 'to_name',
        OTP_CODE: 'otp_code',
        COMPANY_NAME: 'company_name'
    },

    // Registration OTP parameters (ALL 4 REQUIRED - from EmailJS playground)
    REGISTRATION_TEMPLATE_PARAMS: {
        COMPANY_NAME: 'company_name',    // Required parameter 1
        USER_EMAIL: 'user_email',        // Required parameter 2  
        OTP_CODE: 'otp_code',            // Required parameter 3
        TO_EMAIL: 'to_email'             // Required parameter 4
    },

    // OTP Settings
    OTP_LENGTH: 6,
    OTP_EXPIRY_MINUTES: 10,
    RESEND_COOLDOWN_SECONDS: 60,

    // Session Settings
    RESET_SESSION_EXPIRY_MINUTES: 30
};

// Export for use in forgot_password_emailjs.php
if (typeof module !== 'undefined' && module.exports) {
    module.exports = EMAILJS_CONFIG;
}
