<?php
// Version: 2.0; Login

// Registration agreement page.
$txt['registration_agreement'] = 'Registration Agreement';
$txt['registration_privacy_policy'] = 'Privacy Policy';
$txt['agreement_agree'] = 'I accept the terms of the agreement.';
$txt['agreement_no_agree'] = 'I do not accept the terms of the agreement.';
$txt['policy_agree'] = 'I accept the terms of the privacy policy.';
$txt['policy_no_agree'] = 'I do not accept the terms of the privacy policy.';
$txt['agreement_agree_coppa_above'] = 'I accept the terms of the agreement and I am at least %1$d years old.';
$txt['agreement_agree_coppa_below'] = 'I accept the terms of the agreement and I am younger than %1$d years old.';
$txt['agree_coppa_above'] = 'I am at least %1$d years old.';
$txt['agree_coppa_below'] = 'I am younger than %1$d years old.';

// Registration form.
$txt['registration_form'] = 'Registration Form';
$txt['error_too_quickly'] = 'You went through the registration process too quickly, faster than should normally be possible. Please wait a moment and try again.';
$txt['error_token_verification'] = 'Token verification failed. Please try again.';
$txt['error_missing_information'] = 'You need to complete the required fields';
$txt['need_username'] = 'You need to fill in a username.';
$txt['no_password'] = 'You didn\'t enter your password.';
$txt['improper_password'] = 'The supplied password is too long.';
$txt['incorrect_password'] = 'Incorrect Username or Password';
$txt['maintain_mode'] = 'Maintenance Mode';
$txt['registration_successful'] = 'Registration Successful';
$txt['valid_email_needed'] = 'Please enter a valid email address, %1$s.';
$txt['required_info'] = 'Required Information';
$txt['additional_information'] = 'Optional Information';
$txt['warning'] = 'Warning!';
$txt['notice'] = 'Notice';
$txt['only_members_can_access'] = 'Only registered members are allowed to access this section.';
$txt['login_below'] = 'Please login below.';
$txt['login_below_or_register'] = 'Please login below or <a href="%1$s">register an account</a> with %2$s';
$txt['checkbox_agreement'] = 'I accept the registration agreement';
$txt['checkbox_privacypol'] = 'I accept the privacy policy';
$txt['confirm_request_accept_agreement'] = 'Are you sure you want to force all members to accept the agreement?';
$txt['confirm_request_accept_privacy_policy'] = 'Are you sure you want to force all members to accept the privacy policy?';

$txt['login_hash_error'] = 'Password security has recently been upgraded.<br />Please enter your password again.';

$txt['ban_register_prohibited'] = 'Sorry, you are not allowed to register on this forum.';
$txt['under_age_registration_prohibited'] = 'Sorry, but users under the age of %1$d are not allowed to register on this forum.';

$txt['activate_account'] = 'Account activation';
$txt['activate_success'] = 'Your account has been successfully activated. You can now proceed to login.';
$txt['activate_not_completed1'] = 'Your email address needs to be validated before you can login.';
$txt['activate_not_completed2'] = 'Need another activation email?';
$txt['activate_after_registration'] = 'Thank you for registering. You will receive an email soon with a link to activate your account.  If you don\'t receive an email after some time, check your spam folder.';
$txt['invalid_userid'] = 'User does not exist';
$txt['invalid_activation_code'] = 'Invalid activation code';
$txt['invalid_activation_username'] = 'Username or email';
$txt['invalid_activation_new'] = 'If you registered with the wrong email address, type a new one and your password here.';
$txt['invalid_activation_new_email'] = 'New email address';
$txt['invalid_activation_password'] = 'Old password';
$txt['invalid_activation_resend'] = 'Resend activation code';
$txt['invalid_activation_known'] = 'If you already know your activation code, please type it here.';
$txt['invalid_activation_retry'] = 'Activation code';
$txt['invalid_activation_submit'] = 'Activate';

$txt['coppa_no_concent'] = 'The administrator has still not received parent/guardian consent for your account.';
$txt['coppa_need_more_details'] = 'Need more details?';

$txt['awaiting_delete_account'] = 'Your account has been marked for deletion!<br />If you wish to restore your account, please check the &quot;Reactivate my account&quot; box, and login again.';
$txt['undelete_account'] = 'Reactivate my account';

$txt['in_maintain_mode'] = 'This board is in Maintenance Mode.';

// These two are used as a javascript alert; please use international characters directly, not as entities.
$txt['register_agree'] = 'Please read and accept the agreement before registering.';
$txt['register_passwords_differ_js'] = 'The two passwords you entered are not the same!';
$txt['register_did_you'] = 'Did you mean';

$txt['approval_after_registration'] = 'Thank you for registering. The admin must approve your registration before you may begin to use your account, you will receive an email shortly advising you of the admins decision.';

$txt['admin_settings_desc'] = 'Here you can change a variety of settings related to registration of new members.';

$txt['setting_registration_method'] = 'Method of registration employed for new members';
$txt['setting_registration_disabled'] = 'Registration Disabled';
$txt['setting_registration_standard'] = 'Immediate Registration';
$txt['setting_registration_activate'] = 'Email Activation';
$txt['setting_registration_approval'] = 'Admin Approval';
$txt['setting_notify_new_registration'] = 'Notify administrators when a new member joins';
$txt['setting_force_accept_agreement'] = 'Force members to accept the registration agreement when changed';
$txt['force_accept_privacy_policy'] = 'Force members to accept the privacy policy when changed';
$txt['setting_send_welcomeEmail'] = 'Send welcome email to new members';
$txt['setting_show_DisplayNameOnRegistration'] = 'Allow users to enter their screen name';

$txt['setting_coppaAge'] = 'Age below which to apply registration restrictions';
$txt['setting_coppaAge_desc'] = '(Set to 0 to disable)';
$txt['setting_coppaType'] = 'Action to take when a user below minimum age registers';
$txt['setting_coppaType_reject'] = 'Reject their registration';
$txt['setting_coppaType_approval'] = 'Require parent/guardian approval';
$txt['setting_coppaPost'] = 'Postal address to which approval forms should be sent';
$txt['setting_coppaPost_desc'] = 'Only applies if age restriction is in place';
$txt['setting_coppaFax'] = 'Fax number to which approval forms should be faxed';
$txt['setting_coppaPhone'] = 'Contact number for parents to contact with age restriction queries';

$txt['admin_register'] = 'Registration of new member';
$txt['admin_register_desc'] = 'From here you can register new members into the forum, and if desired, email them their details.';
$txt['admin_register_username'] = 'New Username';
$txt['admin_register_email'] = 'Email Address';
$txt['admin_register_password'] = 'Password';
$txt['admin_register_username_desc'] = 'Username for the new member';
$txt['admin_register_email_desc'] = 'Email address of the member';
$txt['admin_register_password_desc'] = 'Password for new member';
$txt['admin_register_email_detail'] = 'Email new password to user';
$txt['admin_register_email_detail_desc'] = 'Email address required even if unchecked';
$txt['admin_register_email_activate'] = 'Require user to activate the account';
$txt['admin_register_group'] = 'Primary Membergroup';
$txt['admin_register_group_desc'] = 'Primary membergroup new member will belong to';
$txt['admin_register_group_none'] = '(no primary membergroup)';
$txt['admin_register_done'] = 'Member %1$s has been registered successfully!';

$txt['coppa_title'] = 'Age Restricted Forum';
$txt['coppa_after_registration'] = 'Thank you for registering with {forum_name_html_safe}.<br /><br />Because you fall under the age of {MINIMUM_AGE}, it is a legal requirement to obtain your parent or guardian\'s permission before you may begin to use your account.  To arrange for account activation please print off the form below:';
$txt['coppa_form_link_popup'] = 'Load Form In New Window';
$txt['coppa_form_link_download'] = 'Download Form as Text File';
$txt['coppa_send_to_one_option'] = 'Then arrange for your parent/guardian to send the completed form by:';
$txt['coppa_send_to_two_options'] = 'Then arrange for your parent/guardian to send the completed form by either:';
$txt['coppa_send_by_post'] = 'Mail/Post, to the following address:';
$txt['coppa_send_by_fax'] = 'Fax, to the following number:';
$txt['coppa_send_by_phone'] = 'Alternatively, arrange for them to phone the administrator at {PHONE_NUMBER}.';

$txt['coppa_form_title'] = 'Permission form for registration at {forum_name_html_safe}';
$txt['coppa_form_address'] = 'Address';
$txt['coppa_form_date'] = 'Date';
$txt['coppa_form_body'] = 'I {PARENT_NAME},<br /><br />give permission for {CHILD_NAME} (child name) to become a fully registered member of the forum: {forum_name_html_safe}, with the username: {USER_NAME}.<br /><br />I understand that certain personal information entered by {USER_NAME} may be shown to other users of the forum.<br /><br />Signed:<br />{PARENT_NAME} (Parent/Guardian).';

// Use numeric entities in the below.
$txt['registration_username_available'] = 'Username is available';
$txt['registration_username_unavailable'] = 'Username is not available';
$txt['registration_username_check'] = 'Check if username is available';
$txt['registration_password_short'] = 'Password is too short';
$txt['registration_password_reserved'] = 'Password contains your username/email';
$txt['registration_password_numbercase'] = 'Password must contain both upper and lower case, and numbers';
$txt['registration_password_no_match'] = 'Passwords do not match';
$txt['registration_password_valid'] = 'Password is valid';

$txt['registration_errors_occurred'] = 'The following errors were detected in your registration. Please correct them to continue:';

$txt['authenticate_label'] = 'Authentication Method';
$txt['authenticate_password'] = 'Password';
$txt['otp_required'] = 'A Time-based One-time Password is required in order to log in!';
$txt['disable_otp'] = 'Disable two factor authentication.';

// Contact form
$txt['admin_contact_form'] = 'Contact the admins';
$txt['contact_your_message'] = 'Your message';
$txt['errors_contact_form'] = 'The following errors occurred while processing your contact request';
$txt['contact_subject'] = 'A guest has sent you a message';
$txt['contact_thankyou'] = 'Thank you for your message. Someone will contact you as soon as possible.';
