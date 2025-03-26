<?php
session_start();
session_destroy();
header("Location: ../users/dashboard.php");
exit;