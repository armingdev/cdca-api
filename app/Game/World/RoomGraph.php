<?php

namespace App\Game\World;

use App\Models\Room;
use Closure;
use SplQueue;

/**
 * The world adjacency held as a plain in-memory array (~2MB at 41k rooms) so
 * BFS never touches the database per hop. Edges are directed — symmetry is
 * observed in the live game, never assumed.
 */
class RoomGraph
{
    /**
     * @param  array<int, array<string, int>>  $adjacency  roomId => [direction => neighbor id]
     */
    public function __construct(private array $adjacency = []) {}

    public static function fromDatabase(): self
    {
        $adjacency = [];

        foreach (Room::query()->get(['id', 'north', 'east', 'south', 'west']) as $room) {
            $adjacency[$room->id] = $room->exits();
        }

        return new self($adjacency);
    }

    public function has(int $roomId): bool
    {
        return isset($this->adjacency[$roomId]);
    }

    /**
     * @return array<string, int>
     */
    public function neighbors(int $roomId): array
    {
        return $this->adjacency[$roomId] ?? [];
    }

    /**
     * @param  array<string, int>  $exits
     */
    public function addRoom(int $roomId, array $exits): void
    {
        $this->adjacency[$roomId] = $exits;
    }

    public function count(): int
    {
        return count($this->adjacency);
    }

    /**
     * Shortest path between two known rooms, inclusive of both ends.
     *
     * @return list<int>|null
     */
    public function shortestPath(int $from, int $to): ?array
    {
        return $this->pathToNearest($from, fn (int $roomId): bool => $roomId === $to);
    }

    /**
     * BFS from $from to the closest room satisfying $predicate; returns the
     * full path (inclusive) or null when no reachable room matches. The
     * predicate is also offered rooms whose exits we don't know yet, so it
     * can target the frontier itself.
     *
     * @param  Closure(int): bool  $predicate
     * @return list<int>|null
     */
    public function pathToNearest(int $from, Closure $predicate): ?array
    {
        if ($predicate($from)) {
            return [$from];
        }

        $parents = [$from => 0];
        $queue = new SplQueue;
        $queue->enqueue($from);

        while (! $queue->isEmpty()) {
            $current = $queue->dequeue();

            foreach ($this->adjacency[$current] ?? [] as $neighbor) {
                if (isset($parents[$neighbor])) {
                    continue;
                }

                $parents[$neighbor] = $current;

                if ($predicate($neighbor)) {
                    return $this->assemblePath($parents, $from, $neighbor);
                }

                if (isset($this->adjacency[$neighbor])) {
                    $queue->enqueue($neighbor);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $parents
     * @return list<int>
     */
    private function assemblePath(array $parents, int $from, int $target): array
    {
        $path = [$target];

        while ($target !== $from) {
            $target = $parents[$target];
            $path[] = $target;
        }

        return array_reverse($path);
    }
}
