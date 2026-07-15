var battle_result = "RealLinuXX gained 15 strength<br>RealLinuXX has gained 1001 experience!<br><img src=/images/goldcoin.gif align=absmiddle /> RealLinuXX gained 125 gold!<br>";

		var attacker_name = "RealLinuXX";
		var defender_name = "Pristine Blader";

		var attacker_gps_name = "krimaward2";
		var attacker_gps_dmg = "68";

		var defender_gps_name = "0";
		var defender_gps_dmg = "0";

		var combat_log = document.getElementById('combat_log');
		var attacker_window = document.getElementById('attacker_window');
		var defender_window = document.getElementById('defender_window');

		var result_notice_window = document.getElementById('result_notice_window');

		var attacker_health = document.getElementById('attacker_health');
		var defender_health = document.getElementById('defender_health');

		var newtimeout = 0;

		var attacker_health_start = 102781;
		var defender_health_start = 80000;

		var attacker_health_new = attacker_health_start;
		var defender_health_new = defender_health_start;

		var total_rounds = 1;

		var attacker_survival = -1;
		var defender_survival = -1;

		var attacker_taken = new Array();
		
		var defender_taken = new Array();