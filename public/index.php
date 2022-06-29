<?php

//--------------------------------------------------
// The library

	class db {

		//--------------------------------------------------
		// Common

			protected int $protection_level = 1;
				// 0 = No checks, could be useful on the production server.
				// 1 = Just warnings, the default.
				// 2 = Exceptions, for anyone who wants to be absolutely sure.

			public function literal_check(mixed $var): void {
				if (!function_exists('is_literal') || is_literal($var)) {
					// Fine - This is a programmer defined string (bingo), or not using PHP 8.1
				} else if ($var instanceof unsafe_value) {
					// Fine - Not ideal, but at least they know this one is unsafe.
				} else if ($this->protection_level === 0) {
					// Fine - Programmer aware, and is choosing to disable this check everywhere.
				} else if ($this->protection_level === 1) {
					trigger_error('Non-literal value detected!', E_USER_WARNING);
				} else {
					throw new Exception('Non-literal value detected!');
				}
			}

			public function enforce_injection_protection(): void {
				$this->protection_level = 2;
			}

			public function unsafe_disable_injection_protection(): void {
				$this->protection_level = 0; // Not recommended, try `new unsafe_value('XXX')` for special cases.
			}

		//--------------------------------------------------
		// Connection

			private mysqli $db;

			public function __construct() {
				$this->db = new mysqli('localhost', 'test', 'test', 'test');
			}

		//--------------------------------------------------
		// Example

			/**
			 * @param literal-string $sql
			 * @param list<int|string> $parameters
			 * @param array<string, string> $aliases
			 */
			public function query(string $sql, array $parameters = [], array $aliases = []): void {

				echo $sql . "\n\n";
				print_r($parameters);
				echo "\n\n";

				$this->literal_check($sql);

				foreach ($aliases as $name => $value) {
					// if (!preg_match('/^[a-z0-9_]+$/', $name))  throw new Exception('Invalid alias name "' . $name . '"');
					// if (!preg_match('/^[a-z0-9_]+$/', $value)) throw new Exception('Invalid alias value "' . $value . '"');
					$sql = str_replace('{' . $name . '}', '`' . str_replace('`', '``', $value) . '`', $sql);
				}

				$statement = $this->db->prepare($sql);

				if ($statement === false) {

					echo 'Cannot Prepare SQL' . "\n";

				} else {

					$statement->execute($parameters);

					$result = $statement->get_result();

					if ($result === false) {

						echo 'Cannot Get Results' . "\n";

					} else {

						while ($row = mysqli_fetch_assoc($result)) {
							print_r($row);
						}

					}

					echo "\n\n";

				}

			}

			/**
			 * @return literal-string
			 */
			public function placeholders(int $count): string {

				// return implode(',', array_fill(0, $count, '?'));

				$sql = '?';
				for ($k = 1; $k < $count; $k++) {
					$sql .= ',?';
				}
				return $sql;

			}

	}

	class unsafe_value {
		private string $value = '';
		function __construct(string $unsafe_value) {
			$this->value = $unsafe_value;
		}
		function __toString(): string {
			return $this->value;
		}
	}

	$db = new db();
	// $db->unsafe_disable_injection_protection();

//--------------------------------------------------
// Example 1


	$id = sprintf((string) ($_GET['id'] ?? '1')); // Use sprintf() to mark as a non-literal string

	$db->query('SELECT name FROM user WHERE id = ?', [$id]);

	echo '-------------------------' . "\n\n";

	$db->query('SELECT name FROM user WHERE id = ' . $id); // INSECURE

	echo '--------------------------------------------------' . "\n";


//--------------------------------------------------
// Example 2


	$parameters = [];

	$where_sql = 'u.deleted IS NULL';



	$name = sprintf((string) ($_GET['name'] ?? 'Amy')); // Use sprintf() to mark as a non-literal string
	if ($name) {

		$where_sql .= ' AND
			u.name LIKE ?';

		$parameters[] = '%' . $name . '%';

	}



	$ids = [1, 2, 3];
	// if (count($ids) > 0) {

		$where_sql .= ' AND
			u.id IN (' . $db->placeholders(count($ids)) . ')';

		$parameters = array_merge($parameters, $ids);

	// }



	$sql = '
		SELECT
			u.name,
			u.email
		FROM
			user AS u
		WHERE
			' . $where_sql;



	$order_by = sprintf((string) ($_GET['sort'] ?? 'email')); // Use sprintf() to mark as a non-literal string
	$order_fields = ['name', 'email'];
	$order_id = array_search($order_by, $order_fields);
	$sql .= '
		ORDER BY
			' . $order_fields[$order_id]; // Limited to known-safe fields.



	$sql .= '
		LIMIT
			?, ?';
	$parameters[] = 0;
	$parameters[] = 3;



	$db->query($sql, $parameters);


	echo '--------------------------------------------------' . "\n\n";


//--------------------------------------------------
// Example 3, field aliases (try to avoid)


	$order_by = sprintf((string) ($_GET['sort'] ?? 'email')); // Use sprintf() to mark as a non-literal string


	$sql = '
		SELECT
			u.name
		FROM
			user AS u
		ORDER BY
			' . $order_by;

	$db->query($sql); // INSECURE


	echo '-------------------------' . "\n\n";


	$sql = '
		SELECT
			u.name
		FROM
			user AS u
		ORDER BY
			{sort}';

	$db->query($sql, [], [
			'sort' => $order_by,
		]);


	echo '--------------------------------------------------' . "\n\n";


//--------------------------------------------------
// Example 4, bit more complex


	$parameters = [];

	$aliases = [
			'with_1'  => sprintf('w1'), // Using sprintf to mark as a non-literal string
			'table_1' => sprintf('user'),
			'field_1' => sprintf('email'),
			'field_2' => sprintf('dob'), // ... All of these are user defined fields.
		];

	$with_sql = '{with_1} AS (SELECT id, name, type, {field_1} as f1, deleted FROM {table_1})';

	$sql = "
		WITH
			$with_sql
		SELECT
			t.name,
			t.f1
		FROM
			{with_1} AS t
		WHERE
			t.type = ? AND
			t.deleted IS NULL";

	$parameters[] = sprintf((string) ($_GET['type'] ?? 'admin')); // Using sprintf to mark as a non-literal string

	$db->query($sql, $parameters, $aliases);


?>