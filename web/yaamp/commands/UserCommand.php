<?php
/**
 * UserCommand is a console command, to delete an user and its history
 *
 * To use this command, enter the following on the command line:
 * <pre>
 * yiic user delete <id|addr>
 * </pre>
 *
 * @property string $help The command description.
 *
 */
class UserCommand extends CConsoleCommand
{
	/**
	 * Execute the action.
	 * @param array $args command line parameters specific for this command
	 * @return integer non zero application exit code after printing help
	 */
	public function run($args)
	{
		$runner=$this->getCommandRunner();
		$commands=$runner->commands;

		if (!isset($args[0]) || $args[0] == 'help') {

			echo "YiiMP user command(s)\n";
			echo "Usage: yiimp user delete <id|address>\n";
			echo "       yiimp user purge [days] (default 180)\n";
			return 1;

		} else if ($args[0] == 'delete') {

			$id = -1; $addr = '';
			if (strlen($args[1]) < 26)
				$id = (int) $args[1];
			else
				$addr = $args[1];
			$this->deleteUser($id, $addr);
			return 0;

		} else if ($args[0] == 'purge') {
			$days = (int) arraySafeVal($args, 1, '180');
			if ($days < 1) return 1;
			$inter = new DateInterval('P'.$days.'D');
			$since = new DateTime;
			$since->sub($inter);
			$nb =  $this->purgeInactiveUsers($since->getTimestamp());
			echo "$nb user(s) deleted\n";
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
	 * Delete user by id or wallet address
	 */
	public function deleteUser($id, $addr)
	{
		$nbDeleted = 0;

		$users = new db_accounts;
		$user = $users->find(array('condition'=>'id=:id OR username=:username', 'params'=>array(
			':id'=>$id, ':username'=>$addr,
		)));
		if ($user && $user->id)	{
			$name = $user->username;
			$nbDeleted += $user->deleteWithDeps();
		} else {
			echo "user not found!\n";
		}
		echo "user $name deleted\n";
	}

	/**
	 * Delete users inactive since a timestamp
	 */
	public function purgeInactiveUsers($ts)
	{
		$nbDeleted = 0;

		$users = new db_accounts;
		$rows = $users->findAll(array(
			// to improve with earnings table
			'condition'=>'last_earning<:ts AND last_earning IS NOT NULL'.
				' AND IFNULL(balance,0)=0 AND IFNULL(donation,0)=0 AND IFNULL(no_fees,0)=0',
			'params'=>array(':ts'=>intval($ts)),
			'order'=>'id ASC'
		));

		if (empty($rows)) {
			$date = strftime("%Y-%m-%d", $ts);
			echo "no user(s) found which are inactive since $date!\n";
			return 0;
		}

		foreach ($rows as $user) {
			if ($user && $user->id)	{
				echo "$user->username\n";
				$nbDeleted += $user->deleteWithDeps();
			}
		}

		return $nbDeleted;
	}
}
