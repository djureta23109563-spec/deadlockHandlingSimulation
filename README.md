# Deadlock Handling Simulator (PHP)

A small educational PHP website for an Operating Systems project. It follows the topic areas in the supplied class material: deadlock detection, avoidance with the Banker's Algorithm, a resource-allocation graph, and prevention concepts.

## Requirements
- PHP 8.0 or later
- Any modern browser
- No database or framework required

## Run locally
1. Extract the ZIP file.
2. Open a terminal in the extracted `deadlock_handling_simulator_php` folder.
3. Run:

   ```bash
   php -S localhost:8000
   ```

4. Open `http://localhost:8000` in your browser.

You can also place the folder inside a local PHP web root such as XAMPP's `htdocs` and open it through localhost.

## Modules
- **Deadlock Detection:** Uses `Available`, `Allocation`, and `Request` to run a multiple-instance deadlock-detection procedure.
- **Banker's Algorithm:** Calculates `Need = Maximum - Allocation` and searches for a safe sequence.
- **Resource Allocation Graph:** Takes directed `Node->Node` edges and detects a directed cycle. A cycle is sufficient for deadlock only for resource types with one instance each; with multiple instances, a cycle alone is not conclusive.
- **Deadlock Prevention:** Briefly explains the four necessary conditions and example strategies that prevent one or more conditions from holding.

## Input format
- Vectors: integers separated by commas or spaces, e.g. `1, 0, 2`
- Matrices: one process per line; integers separated by commas or spaces, e.g. `0, 1, 0`
- Graph edges: use `P0->R0; R0->P1; P1->R1; R1->P0`

## Notes for the presentation
- Explain the algorithm before showing its output.
- Use consistent resource-column order in every vector and matrix.
- In the detection module, `P0`, `P1`, etc. correspond to the first, second, and later matrix rows.
- An unsafe state is not identical to a current deadlock. The Banker's Algorithm checks safety/avoidance, while the detection module checks which processes remain unfinished.
- This is a classroom simulator, not a production operating-system tool. Verify all sample inputs and results against your instructor's preferred algorithm and terminology.
