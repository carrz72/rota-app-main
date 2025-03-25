<?php
session_start();
session_destroy();
header("Location:  /rota-app/users/dashboard.php");
exit;