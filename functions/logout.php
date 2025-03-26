<?php
session_start();
session_destroy();
header("Location: ../functions/login.php");
exit;