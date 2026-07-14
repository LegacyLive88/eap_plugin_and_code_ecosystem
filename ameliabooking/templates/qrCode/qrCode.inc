<?php

use AmeliaBooking\Infrastructure\WP\Translations\BackendStrings;

$data = $qrDataItem;

?>
<!DOCTYPE html>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <style>
      body { margin: 0; font-family: 'DejaVu Sans', sans-serif; line-height: 15px; }
      html { margin: 0; font-family: 'DejaVu Sans', sans-serif; line-height: 15px;}
      @page { margin: 0; font-family: 'DejaVu Sans', sans-serif; line-height: 15px;}
      .am-ticket {
          width: 280px;
          margin: 50px auto 0;
          background: #fff;
          overflow: hidden;
      }
      .am-ticket__qr {
          text-align: center;
          padding: 20px;
          border-radius: 8px;
          border: 1px solid #667279;
          border-bottom: 1px dashed #667279;
      }
      .am-ticket__qr img {
          width: 160px;
          height: 160px;
      }
      .am-ticket__content {
          padding: 20px;
          text-align: center;
          border-radius: 8px;
          border: 1px solid #667279;
          border-top: none;
      }
      .am-ticket__label {
          color: #667279;
          font-size: 14px;
          margin-bottom: 6px;
      }
      .am-ticket__value {
          font-weight: bold;
          color: #0E1920;
      }
      .am-ticket__title {
          font-size: 18px;
          font-weight: bold;
          margin: 12px 0;
          line-height: 1.3;
          color: #0E1920;
      }
      .am-ticket__datetime {
          font-size: 14px;
          color: #0E1920;
          margin-bottom: 4px;
      }
      .am-ticket__location {
          font-size: 14px;
          color: #0E1920;
          margin-bottom: 12px;
      }
      .am-ticket__booking {
          font-size: 13px;
          color: #667279;
      }
      .am-ticket__booking span {
          font-weight: bold;
          color: #0E1920;
      }
      .am-ticket__type {
          font-size: 13px;
          color: #667279;
      }
      .am-ticket__type span {
          font-weight: bold;
          color: #0E1920;
      }

  </style>
  <title><?php echo BackendStrings::get('e_ticket') ?></title>
</head>
<body>
  <div class="am-ticket">
    <div class="am-ticket__qr">
      <?php echo '<img src="data:' . $data['mimeType'] . ';base64,' .$data['data'] . ' " alt="'. $data['eventName'] . 'Ticket" />'; ?>
    </div>
    <div class="am-ticket__content">
      <div class="am-ticket__title">
        <?php echo $data['eventName']; ?>
      </div>
      <div class="am-ticket__label">
        <?php echo BackendStrings::get('ticket_id') ?>: <span class="am-ticket__value">#<?php echo $data['ticketManualCode'] ?></span>
      </div>
      <?php if (array_key_exists('eventTicketName', $data)) : ?>
        <div class="am-ticket__label">
          <?php echo BackendStrings::get('ticket_name') ?>: <span class="am-ticket__value"><?php echo $data['eventTicketName'] ?></span>
        </div>
      <?php endif; ?>
      <div class="am-ticket__datetime">
        <?php echo $data['eventStartDateTime']; ?>
      </div>
      <?php if (array_key_exists('eventLocation', $data)) : ?>
      <div class="am-ticket__location">
        <?php echo $data['eventLocation'] ?>
      </div>
      <?php endif; ?>
      <div class="am-ticket__booking">
        <?php echo BackendStrings::get('booking_id') ?>: <span>#<?php echo $data['bookingId'] ?></span>
      </div>
      <div class="am-ticket__type">
        <?php echo BackendStrings::get('ticket_type') ?>: <span><?php echo $data['type'] === 'ticket' ? BackendStrings::get('single_ticket') : BackendStrings::get('group_ticket'); ?></span>
    </div>
  </div>

</body>
</html>
