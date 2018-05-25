kill -9 `ps -ax | grep robot | grep -v grep | awk '{print $1}' `;
php robot_stop.php