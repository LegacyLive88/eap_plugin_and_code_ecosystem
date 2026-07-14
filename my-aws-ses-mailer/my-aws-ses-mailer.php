<?php
/**
 * Plugin Name: AWS SES Mailer Integration
 * Description: Intercepts wp_mail() to send emails via Amazon SES API, including attachments. Includes a settings page for testing.
 * Version: 1.2
 * Author: Legacy Live
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// 1. Load Composer Autoloader
// Ensure you ran 'composer require aws/aws-sdk-php' in this plugin's folder
$autoload_path = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
if ( file_exists( $autoload_path ) ) {
    require_once $autoload_path;
}

use Aws\Ses\SesClient;
use Aws\Exception\AwsException;

/**
 * ==============================================================================
 * CORE FUNCTIONALITY: Intercept wp_mail
 * ==============================================================================
 */
add_filter( 'pre_wp_mail', 'ses_override_wp_mail', 10, 2 );

function ses_override_wp_mail( $return, $atts ) {
    // Check if our constants are defined in wp-config.php
    if ( ! defined( 'AWS_SES_KEY' ) || ! defined( 'AWS_SES_SECRET' ) || ! defined( 'AWS_SES_REGION' ) ) {
        return null; // Return null to let WordPress fall back to default mailer
    }

    // Extract arguments from wp_mail()
    $to          = $atts['to'];
    $subject     = $atts['subject'];
    $message     = $atts['message'];
    $headers     = $atts['headers'];
    $attachments = $atts['attachments'];

    // Normalize 'To' Address
    if ( ! is_array( $to ) ) {
        $to = explode( ',', $to );
    }
    $to = array_map( 'trim', $to );

    // Normalize attachments to an array of file paths. WordPress passes either
    // a newline-delimited string or an array.
    if ( ! is_array( $attachments ) ) {
        $attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
    }
    $attachments = array_filter( array_map( 'trim', $attachments ) );

    // Parse Headers
    $parsed_headers = ses_parse_headers( $headers );
    
    // Default "From"
    if ( empty( $parsed_headers['From'] ) ) {
        $from_email = apply_filters( 'wp_mail_from', 'secretariat@app.eapaediatrics.eu' );
        $from_name  = apply_filters( 'wp_mail_from_name', 'Secretariat - European Academy of Paediatrics' );
        $source     = sprintf( '%s <%s>', $from_name, $from_email );
    } else {
        $source = $parsed_headers['From'];
    }

    // Determine HTML vs Text
    $is_html = false;
    if ( isset( $parsed_headers['Content-Type'] ) && stripos( $parsed_headers['Content-Type'], 'text/html' ) !== false ) {
        $is_html = true;
        $message = "<div style='text-align:center;border-top:10px solid #003b7f;border-bottom:10px solid #003b7f;padding:10px;width:100%;'>
        <img style='width:100%;max-width:420px;' src='https://eapaediatrics.eu/wp-content/uploads/2024/09/cropped-eap_logo.png' alt='EAP Logo'>
        </div>
        <br><br>".$message;
    }
    if ( apply_filters( 'wp_mail_content_type', '' ) === 'text/html' ) {
        $is_html = true;
    }

    // Initialize AWS SES Client
    try {
        $client = new SesClient([
            'version' => 'latest',
            'region'  => AWS_SES_REGION,
            'credentials' => [
                'key'    => AWS_SES_KEY,
                'secret' => AWS_SES_SECRET,
            ],
        ]);

        // Attachments cannot be sent via the simple SendEmail API, so build a
        // full MIME message and use the raw endpoint instead.
        if ( ! empty( $attachments ) ) {
            $raw_message = ses_build_raw_message( $source, $to, $parsed_headers, $subject, $message, $is_html, $attachments );

            // Envelope recipients (To + Cc + Bcc) as plain addresses. Passing
            // these explicitly ensures Bcc recipients are delivered even though
            // the Bcc header is stripped from the MIME body.
            $destinations = [];
            foreach ( array_merge( $to, $parsed_headers['Cc'], $parsed_headers['Bcc'] ) as $addr ) {
                list( $addr_email ) = ses_split_address( $addr );
                if ( $addr_email ) {
                    $destinations[] = $addr_email;
                }
            }

            list( $source_email ) = ses_split_address( $source );

            $client->sendRawEmail([
                'Source'       => $source_email,
                'Destinations' => $destinations,
                'RawMessage'   => [ 'Data' => $raw_message ],
            ]);

            return true; // Success
        }

        // Construct email
        $email_request = [
            'Source' => $source,
            'Destination' => [
                'ToAddresses' => $to,
                'CcAddresses' => $parsed_headers['Cc'] ?? [],
                'BccAddresses' => $parsed_headers['Bcc'] ?? [],
            ],
            'Message' => [
                'Subject' => [ 'Data' => $subject, 'Charset' => 'UTF-8' ],
                'Body' => [
                    $is_html ? 'Html' : 'Text' => [ 'Data' => $message, 'Charset' => 'UTF-8' ],
                ],
            ],
        ];

        // Send Email
        $client->sendEmail( $email_request );
        
        return true; // Success

    } catch ( AwsException $e ) {
        error_log( "AWS SES Error: " . $e->getAwsErrorMessage() );
        return false; // Fail
    } catch ( Exception $e ) {
        error_log( "AWS SES General Error: " . $e->getMessage() );
        return null; // Fallback on general error
    }
}

function ses_parse_headers( $headers ) {
    $output = [ 'From' => '', 'Cc' => [], 'Bcc' => [], 'Content-Type' => '' ];
    if ( empty( $headers ) ) return $output;

    if ( is_string( $headers ) ) {
        $headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
    }

    foreach ( $headers as $header ) {
        if ( strpos( $header, ':' ) === false ) continue;
        list( $name, $content ) = explode( ':', trim( $header ), 2 );
        switch ( strtolower( trim( $name ) ) ) {
            case 'from': $output['From'] = trim( $content ); break;
            case 'content-type': $output['Content-Type'] = trim( $content ); break;
            case 'cc': $output['Cc'][] = trim( $content ); break;
            case 'bcc': $output['Bcc'][] = trim( $content ); break;
        }
    }
    return $output;
}

/**
 * Split an address string such as "Name <email@example.com>" into
 * [ email, name ]. A bare address returns [ email, '' ].
 *
 * @param string $address
 * @return array{0:string,1:string}
 */
function ses_split_address( $address ) {
    $address = trim( (string) $address );
    if ( preg_match( '/^(.*)<(.+)>\s*$/', $address, $m ) ) {
        $name  = trim( $m[1], " \t\"'" );
        $email = trim( $m[2] );
        return [ $email, $name ];
    }
    return [ $address, '' ];
}

/**
 * Build a raw MIME message (headers + body + attachments) using the copy of
 * PHPMailer bundled with WordPress. This is required to send attachments via
 * the Amazon SES SendRawEmail API.
 *
 * @return string The full MIME message, ready for SES RawMessage['Data'].
 */
function ses_build_raw_message( $source, $to, $parsed_headers, $subject, $message, $is_html, $attachments ) {
    if ( ! class_exists( '\\PHPMailer\\PHPMailer\\PHPMailer' ) ) {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    }

    $mail          = new \PHPMailer\PHPMailer\PHPMailer( true );
    $mail->CharSet = 'UTF-8';

    // From
    list( $from_email, $from_name ) = ses_split_address( $source );
    $mail->setFrom( $from_email, $from_name, false );

    // Recipients
    foreach ( (array) $to as $addr ) {
        list( $email, $name ) = ses_split_address( $addr );
        if ( $email ) {
            $mail->addAddress( $email, $name );
        }
    }
    foreach ( $parsed_headers['Cc'] as $addr ) {
        list( $email, $name ) = ses_split_address( $addr );
        if ( $email ) {
            $mail->addCC( $email, $name );
        }
    }
    foreach ( $parsed_headers['Bcc'] as $addr ) {
        list( $email, $name ) = ses_split_address( $addr );
        if ( $email ) {
            $mail->addBCC( $email, $name );
        }
    }

    $mail->Subject = $subject;
    $mail->isHTML( (bool) $is_html );
    $mail->Body = $message;
    if ( $is_html ) {
        $mail->AltBody = wp_strip_all_tags( $message );
    }

    // Attachments
    foreach ( $attachments as $path ) {
        if ( is_readable( $path ) ) {
            $mail->addAttachment( $path );
        } else {
            error_log( 'AWS SES Plugin: Attachment not readable, skipping: ' . $path );
        }
    }

    // Assemble the message without dispatching it, then hand the raw MIME to SES.
    $mail->preSend();
    return $mail->getSentMIMEMessage();
}

/**
 * ==============================================================================
 * ADMIN UI: Settings & Test Page
 * ==============================================================================
 */

add_action( 'admin_menu', 'ses_register_test_page' );

function ses_register_test_page() {
    add_options_page(
        'AWS SES Test',       // Page Title
        'AWS SES Test',       // Menu Title
        'manage_options',     // Capability required
        'aws-ses-test',       // Menu Slug
        'ses_render_test_page' // Callback function
    );
}

function ses_render_test_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // 1. Handle Test Email Submission
    $message = '';
    $status  = '';

    if ( isset( $_POST['ses_test_submit'] ) && check_admin_referer( 'ses_send_test_email', 'ses_nonce' ) ) {
        $test_email = sanitize_email( $_POST['ses_test_email'] );
        
        if ( is_email( $test_email ) ) {
            // We use wp_mail() here. If the plugin is working, it will route through ses_override_wp_mail automatically.
            $sent = wp_mail( 
                $test_email, 
                'AWS SES Integration Test - ' . get_bloginfo('name'), 
                '<h1>It Works!</h1><p>This email was sent via the <code>wp_mail()</code> function.</p><p>If the AWS SES plugin is active, this was delivered via Amazon SES.</p>', 
                ['Content-Type: text/html; charset=UTF-8'] 
            );

            if ( $sent ) {
                $status = 'success';
                $message = "Test email sent to <strong>$test_email</strong>. Please check your inbox (and spam folder).";
            } else {
                $status = 'error';
                $message = "Test email failed. Please check your <code>debug.log</code> for AWS error messages.";
            }
        } else {
            $status = 'warning';
            $message = "Please enter a valid email address.";
        }
    }

    // 2. Check Configuration Status for Display
    $config_check = [
        'AWS_SES_KEY'    => defined( 'AWS_SES_KEY' ) ? 'Defined' : 'Missing',
        'AWS_SES_SECRET' => defined( 'AWS_SES_SECRET' ) ? 'Defined' : 'Missing',
        'AWS_SES_REGION' => defined( 'AWS_SES_REGION' ) ? constant( 'AWS_SES_REGION' ) : 'Missing',
        'Vendor Folder'  => file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ? 'Found' : 'Missing (Run composer install)',
    ];

    // 3. Render the Admin Page
    ?>
    <div class="wrap">
        <h1>AWS SES Integration Testing</h1>
        
        <?php if ( ! empty( $message ) ) : ?>
            <div class="notice notice-<?php echo $status; ?> is-dismissible">
                <p><?php echo $message; ?></p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2 class="title">Configuration Status</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Constant / Requirement</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach( $config_check as $key => $val ): ?>
                        <tr>
                            <td><strong><?php echo esc_html( $key ); ?></strong></td>
                            <td>
                                <?php if ( strpos( $val, 'Missing' ) !== false ): ?>
                                    <span style="color: #d63638; font-weight: bold;"><?php echo esc_html( $val ); ?></span>
                                <?php else: ?>
                                    <span style="color: #00a32a; font-weight: bold;"><?php echo esc_html( $val ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="margin-top: 10px;">
                Define these constants in your <code>wp-config.php</code> file.
            </p>
        </div>

        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2 class="title">Send a Test Email</h2>
            <p>Enter an email address below. This will trigger the standard <code>wp_mail()</code> function, which this plugin intercepts.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'ses_send_test_email', 'ses_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ses_test_email">Recipient Email</label></th>
                        <td>
                            <input name="ses_test_email" type="email" id="ses_test_email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="ses_test_submit" id="submit" class="button button-primary" value="Send Test Email">
                </p>
            </form>
        </div>
    </div>
    <?php
}