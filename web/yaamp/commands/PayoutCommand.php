<?php
/**
 * PayoutCommand is a console command :
 *  - check: compare wallet's chain history and database payouts
 *
 * To use this command, enter the following on the command line:
 * <pre>
 * yiimp payout check LYB
 * </pre>
 *
 * @property string $help The command description.
 *
 */
class PayoutCommand extends CConsoleCommand
{
	protected $basePath;

	/**
	 * Execute the action.
	 * @param array $args command line parameters specific for this command
	 * @return integer non zero application exit code after printing help
	 */
	public function run($args)
	{
		$runner=$this->getCommandRunner();
		$commands=$runner->commands;

		$root = realpath(Yii::app()->getBasePath().DIRECTORY_SEPARATOR.'..');
		$this->basePath = str_replace(DIRECTORY_SEPARATOR, '/', $root);

		$command = arraySafeVal($args,0);
		$coinsym = arraySafeVal($args,1);
		$fixit = arraySafeVal($args,2); // optional

		if (!isset($args[1]) || empty($coinsym) || $args[1] == 'help') {

			echo "Yiimp payout command\n";
			echo "Usage: yiimp payout check <symbol> [fixit]\n";
			return 1;

		} elseif ($command == 'check') {

			$nbUpdated  = $this->checkPayouts($coinsym, $fixit);
			echo "total updated: $nbUpdated\n";
			return 0;
		}
	}

	/**
	 * Provides the command description.
	 * @return string the command description.
	 */
	public function getHelp()
	{
		return $this->run(array('help'));
	}

	/**
	 * Check in a wallet completed payouts and missing/extra ones
	 */
	public function checkPayouts($symbol, $fixit)
	{
		$nbUpdated = 0; $nbCreated = 0;

		$coin = getdbosql('db_coins', "symbol=:symbol", array(':symbol'=>$symbol));
		if (!$coin) {
			echo "wallet $symbol not found!\n";
			return 0;
		}

		// failed payouts, generally related to bad wallet 'accounts' balances (VNL)
		$dbPayouts = new db_payouts;
		$min_payout = max($coin->txfee, floatval(YAAMP_PAYMENTS_MINI));
		$failed_payouts = $dbPayouts->with('account')->findAll(array(
			'condition'=>"tx IS NULL AND amount > $min_payout AND account.coinid = ".$coin->id,
			'order'=>'time DESC',
		));

		$condOr = '';
		if (!empty($failed_payouts)) {
			$ids = array();
			$sum = 0.;
			foreach ($failed_payouts as $payout) {
				$uid = (int) $payout['account_id'];
				$ids[$uid] = floatval($payout['amount']) + arraySafeVal($ids, $uid, 0.);
				$sum += floatval($payout['amount']);
			}
			echo "failed payouts detected for ".count($ids)." account(s), $sum {$coin->symbol}\n";
			$condOr = "OR A.id IN (".implode(',', array_keys($ids)).')';
		}

		// Get users using the coin...
		$users = dbolist("SELECT DISTINCT A.id AS userid, A.username AS username ".
			"FROM accounts A LEFT JOIN coins C ON C.id = A.coinid ".
			"WHERE A.coinid={$coin->id} AND (A.balance > 0.0 $condOr)"
		);
		$ids = array();
		foreach ($users as $uids) {
			$uid = (int) $uids['userid'];
			$ids[$uid] = $uids['username'];
		}
		if (empty($ids))
			return 0;

		// Get their payouts
		$dbPayouts = new db_payouts;
		$payouts = $dbPayouts->findAll(array(
			'condition'=>"account_id IN (".implode(',',array_keys($ids)).')',
			'order'=>'time DESC',
		));

		if (empty($payouts) || empty($ids))
			return 0;

		$remote = new WalletRPC($coin);
		$account = '';
		if ($coin->rpcencoding == 'DCR') $account = '*';
		$rawtxs = $remote->listtransactions($account, 25000);

		foreach ($ids as $uid => $user_addr)
		{
			$totalsent = 0.0; $totalpayouts = 0.0;

			// check for previous resolved problems
			$since = (int) dboscalar("SELECT MAX(time) as time FROM payouts WHERE account_id=:uid AND fee > 0.0",
				array(':uid'=>$uid)
			);

			// else check the last week
			if (empty($since)) $since = time()-(7*24*3600);

			// Get db payouts
			$payouts = $dbPayouts->findAll(array(
				'condition'=>"account_id=$uid AND time >= ".intval($since),
				'order'=>'time DESC',
			));
			if (empty($payouts)) $payouts = array();

			echo "$user_addr payouts since ".strftime('%F %c', $since).": ".count($payouts)."\n";

			// filter user raw transactions
			foreach ($rawtxs as $ntx => $tx) {
				$time = arraySafeVal($tx,'time');
				if ($time < $since) continue;
				$match = false;
				if (arraySafeVal($tx,'category') == 'send' && arraySafeVal($tx,'address') == $user_addr) {
					$amount = abs(arraySafeVal($tx,'amount'));
					$txid = arraySafeVal($tx,'txid');
					$totalsent += $amount + (float) abs(arraySafeVal($tx,'fee'));

					foreach ($payouts as $payout) {
						if ($payout->tx == $txid && round($payout->amount) == round($amount)) {
							$totalpayouts += $amount + (float) abs(arraySafeVal($tx,'fee'));
							$match = true;
							if (arraySafeVal($tx, 'confirmations') > 5) {
								$payout->completed = 1;
								$nbUpdated += $payout->save();
								//echo "tx {$payout->tx} {$payout->amount} $symbol confirmed\n";
							}
							break;
						} elseif ($payout->tx == $txid) {
							echo "tx {$payout->tx} {$payout->amount} $symbol != $amount $symbol (possible match)\n";
							$match = true;
						}
					}
					// These extra payouts will be shown during 24h in the user wallet txs
					if (!$match && $fixit == 'fixit') {
						// do it manually with the fixit cmdline argument (need manual checks)
						$payout = new db_payouts;
						$payout->account_id = $uid;
						$payout->tx = $txid;
						$payout->time = $time;
						$payout->completed = 1;
						$payout->amount = $amount;
						$payout->fee = abs(arraySafeVal($tx,'fee'));
						$nbCreated += $payout->save();
						$user = getdbo('db_accounts', $uid);
						if ($user) {
							$user->balance = floatval($user->balance) - $amount;
							dborun("UPDATE balanceuser SET balance = (balance - $amount) WHERE userid=$uid AND time>=$time");
							$user->save();
						}
						$match = true;
						$time = strftime('%F %c', $time);
						echo "extra user tx $txid $time $amount $symbol\n";
					}
				}
				//if (0 && !$match && arraySafeVal($tx,'category') == 'send') {
				//	$time = strftime('%F %c', $time);
				//	$txid = arraySafeVal($tx,'txid');
				//	$amount = abs(arraySafeVal($tx,'amount'));
				//	$address = arraySafeVal($tx,'address');
				//	echo "unknown tx $txid $time $amount $symbol to $address\n";
				//}
			}
			// get the extra payouts
			$payouts = $dbPayouts->findAll(array(
				'condition'=>"completed=0 AND account_id=$uid AND time > ".intval($since),
				'order'=>'time DESC',
			));

			$totaldiff = $totalsent - $totalpayouts;
			if ($totaldiff > 0.0) {
				// search payouts not in db
				foreach ($payouts as $payout) {
					$time = strftime('%F %c', $payout->time);
					echo "extra db tx: $time {$payout->tx} {$payout->amount} $symbol\n";
				}
			}
			if ($totaldiff != 0.0)
				echo "$user_addr: Total sent $totalsent (real), $totalpayouts (db) -> Diff $totaldiff $symbol\n";
			else
				echo "$user_addr: ok\n";
		}

		if ($nbCreated)
			echo "$nbUpdated payouts confirmed, $nbCreated payouts created\n";
		else if ($nbUpdated)
			echo "$nbUpdated payouts confirmed\n";
		return $nbCreated;
	}

}
