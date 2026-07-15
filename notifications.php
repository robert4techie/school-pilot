<?php

function setNotification($type, $message) {
    $_SESSION['notification'] = [
        'type' => $type,
        'message' => $message
    ];
}

function displayNotification() {
    if (isset($_SESSION['notification'])) {
        $notification = $_SESSION['notification'];
        $class = '';
        $icon = '';
        
        switch ($notification['type']) {
            case 'success':
                $class = 'alert-success';
                $icon = 'fa-check-circle';
                break;
            case 'error':
                $class = 'alert-danger';
                $icon = 'fa-exclamation-circle';
                break;
            case 'warning':
                $class = 'alert-warning';
                $icon = 'fa-exclamation-triangle';
                break;
            default:
                $class = 'alert-info';
                $icon = 'fa-info-circle';
        }
        
        echo '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">
                <i class="fas ' . $icon . '"></i> ' . $notification['message'] . '
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
              </div>';
        
        unset($_SESSION['notification']);
    }
}
?>